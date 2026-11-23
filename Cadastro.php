<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 16384) {
        respond(400, ['error' => 'O conteúdo recebido está vazio ou passou do limite permitido.']);
    }

    try {
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        respond(400, ['error' => 'Os dados recebidos não estão em formato JSON válido.']);
    }

    if (!is_array($body)) {
        respond(400, ['error' => 'O formato do conteúdo enviado não foi reconhecido.']);
    }

    return $body;
}

function requiredText(array $data, string $field, int $maxLength, string $label): string
{
    $value = $data[$field] ?? null;
    if (!is_string($value)) {
        respond(422, ['error' => "Informe $label."]);
    }

    $value = trim($value);
    if ($value === '' || preg_match('/^.{1,' . $maxLength . '}$/us', $value) !== 1) {
        respond(422, ['error' => "$label precisa conter de 1 a $maxLength caracteres."]);
    }

    return $value;
}

function formatCpf(string $cpf): string
{
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

try {
    $configuredPath = getenv('MINI_LOJA_DB_PATH');
    $dataParent = dirname(__DIR__);
    $databasePath = $configuredPath !== false && $configuredPath !== ''
        ? $configuredPath
        : $dataParent . DIRECTORY_SEPARATOR . 'Mini-Loja-cadu-data' . DIRECTORY_SEPARATOR . 'loja.sqlite';
    $legacyDatabasePath = $dataParent . DIRECTORY_SEPARATOR . 'Mini-Loja-data' . DIRECTORY_SEPARATOR . 'loja.sqlite';

    if (
        ($configuredPath === false || $configuredPath === '')
        && !is_file($databasePath)
        && is_file($legacyDatabasePath)
    ) {
        $newDatabaseDirectory = dirname($databasePath);
        if (!is_dir($newDatabaseDirectory)) {
            mkdir($newDatabaseDirectory, 0770, true);
        }
        copy($legacyDatabasePath, $databasePath);
    }

    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Não foi possível preparar a pasta para o banco de dados.');
    }

    $database = new PDO('sqlite:' . $databasePath);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price_cents INTEGER NOT NULL CHECK (price_cents > 0),
            description TEXT NOT NULL,
            illustration TEXT NOT NULL
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            cpf TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL
        )'
    );
    $database->exec('CREATE TABLE IF NOT EXISTS app_settings (setting TEXT PRIMARY KEY, value TEXT NOT NULL)');

    $database->beginTransaction();
    $seedMarker = $database->prepare(
        "INSERT OR IGNORE INTO app_settings (setting, value) VALUES ('catalog_seeded', '1')"
    );
    $seedMarker->execute();
    if ($seedMarker->rowCount() > 0) {
        $starterProducts = [
            ['Camiseta essencial', 3990, 'Uma peça leve e confortável para qualquer ocasião.', 'shirt'],
            ['Caneca do dia', 2490, 'Cerâmica resistente para café, chá ou chocolate quente.', 'mug'],
            ['Caderno de ideias', 1850, 'Páginas pautadas para projetos, estudos e lembretes.', 'notebook'],
            ['Garrafa térmica', 5990, 'Acompanha bebidas quentes ou geladas ao longo do dia.', 'bottle'],
            ['Sacola reutilizável', 2990, 'Uma bolsa prática para compras e atividades diárias.', 'tote'],
            ['Estojo compacto', 2200, 'Organiza lápis, acessórios e pequenos objetos.', 'case'],
            ['Boné urbano', 4500, 'Fecho ajustável e acabamento confortável.', 'cap'],
            ['Chaveiro minimalista', 990, 'Um detalhe simples para identificar suas chaves.', 'keyring'],
            ['Agenda semanal', 3490, 'Organize encontros, metas e compromissos da semana.', 'calendar'],
            ['Mochila de percurso', 8990, 'Espaço amplo para trabalho, estudo e passeios.', 'backpack']
        ];
        $insertProduct = $database->prepare(
            'INSERT INTO products (name, price_cents, description, illustration) VALUES (?, ?, ?, ?)'
        );
        foreach ($starterProducts as $product) {
            $insertProduct->execute($product);
        }
    }
    $database->commit();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $products = $database->query(
            'SELECT id, name, price_cents, description, illustration FROM products ORDER BY id'
        )->fetchAll();
        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['price'] = (int) $product['price_cents'] / 100;
            unset($product['price_cents']);
        }
        unset($product);

        $employees = $database->query('SELECT id, name, cpf, email FROM employees ORDER BY id')->fetchAll();
        foreach ($employees as &$employee) {
            $employee['id'] = (int) $employee['id'];
            $employee['cpf'] = formatCpf($employee['cpf']);
        }
        unset($employee);

        respond(200, ['products' => $products, 'employees' => $employees]);
    }

    if ($method === 'POST') {
        $body = readJsonBody();
        $type = $body['type'] ?? '';

        if ($type === 'product') {
            $name = requiredText($body, 'name', 80, 'o nome do produto');
            $description = requiredText($body, 'description', 240, 'a descrição do produto');
            $price = $body['price'] ?? null;
            if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0 || (float) $price > 9999999.99) {
                respond(422, ['error' => 'Use um valor acima de R$ 0,00 e abaixo de R$ 10.000.000,00.']);
            }

            $priceCents = (int) round((float) $price * 100);
            $insert = $database->prepare(
                'INSERT INTO products (name, price_cents, description, illustration) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$name, $priceCents, $description, 'package']);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        if ($type === 'employee') {
            $name = requiredText($body, 'name', 100, 'o nome do funcionário');
            $email = requiredText($body, 'email', 120, 'o e-mail');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                respond(422, ['error' => 'Informe um e-mail válido.']);
            }
            $cpfValue = $body['cpf'] ?? null;
            if (!is_string($cpfValue)) {
                respond(422, ['error' => 'Preencha o CPF usando exatamente 11 números.']);
            }
            $cpf = preg_replace('/\D/', '', $cpfValue);
            if (strlen($cpf) !== 11) {
                respond(422, ['error' => 'Preencha o CPF usando exatamente 11 números.']);
            }

            $insert = $database->prepare('INSERT INTO employees (name, cpf, email) VALUES (?, ?, ?)');
            $insert->execute([$name, $cpf, $email]);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        respond(400, ['error' => 'Não foi possível identificar o tipo de registro.']);
    }

    if ($method === 'DELETE') {
        $type = $_GET['type'] ?? '';
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($type, ['product', 'employee'], true) || $id === false || $id < 1) {
            respond(400, ['error' => 'Os dados informados para excluir o registro são inválidos.']);
        }

        $table = $type === 'product' ? 'products' : 'employees';
        $delete = $database->prepare("DELETE FROM $table WHERE id = ?");
        $delete->execute([$id]);
        if ($delete->rowCount() === 0) {
            respond(404, ['error' => 'Nenhum registro correspondente foi localizado.']);
        }

        respond(200, ['deleted' => true]);
    }

    header('Allow: GET, POST, DELETE');
    respond(405, ['error' => 'Este método de acesso não é permitido.']);
} catch (PDOException $error) {
    error_log('Falha no banco da loja: ' . $error->getMessage());
    if ($error->getCode() === '23000') {
        respond(409, ['error' => 'Já existe um colaborador com este CPF.']);
    }
    respond(500, ['error' => 'Não foi possível consultar o banco. Confira a configuração do PHP e do SQLite.']);
} catch (Throwable $error) {
    error_log('Falha na API da loja: ' . $error->getMessage());
    respond(500, ['error' => 'A operação não pôde ser concluída por um erro interno.']);
}