<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

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

function requireLogin(): void
{
    if (empty($_SESSION['employee_id'])) {
        respond(401, ['error' => 'Entre com seu login para fazer esta alteração.']);
    }
}

function saveImage(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2097152) {
        respond(422, ['error' => 'Envie uma imagem de até 2 MB.']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($types[$mime]) || getimagesize($file['tmp_name']) === false) {
        respond(422, ['error' => 'Use uma imagem JPG, PNG, WEBP ou GIF.']);
    }
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
        throw new RuntimeException('Falha ao salvar a imagem.');
    }
    return 'uploads/' . $name;
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
    $productColumns = array_column($database->query('PRAGMA table_info(products)')->fetchAll(), 'name');
    if (!in_array('image_path', $productColumns, true)) {
        $database->exec('ALTER TABLE products ADD COLUMN image_path TEXT');
    }
    $employeeColumns = array_column($database->query('PRAGMA table_info(employees)')->fetchAll(), 'name');
    if (!in_array('password_hash', $employeeColumns, true)) {
        $database->exec('ALTER TABLE employees ADD COLUMN password_hash TEXT');
    }

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

    $starterImages = [
        'shirt' => 'camiseta', 'mug' => 'caneca', 'notebook' => 'caderno', 'bottle' => 'garrafatermica',
        'tote' => 'sacola', 'case' => 'estojo', 'cap' => 'bone', 'keyring' => 'chaveiro',
        'calendar' => 'agenda', 'backpack' => 'mochila'
    ];
    $imageMarker = $database->prepare("INSERT OR IGNORE INTO app_settings (setting, value) VALUES ('starter_images', '1')");
    $imageMarker->execute();
    if ($imageMarker->rowCount() > 0) {
        $setImage = $database->prepare('UPDATE products SET image_path = ? WHERE illustration = ? AND image_path IS NULL');
        foreach ($starterImages as $illustration => $file) {
            $setImage->execute(['imagens/' . $file . '.jpg', $illustration]);
        }
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $products = $database->query(
            'SELECT id, name, price_cents, description, illustration, image_path FROM products ORDER BY id'
        )->fetchAll();
        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['price'] = (int) $product['price_cents'] / 100;
            unset($product['price_cents']);
            $product['image'] = $product['image_path'];
            unset($product['image_path']);
        }
        unset($product);

        $loggedIn = !empty($_SESSION['employee_id']);
        $employees = $loggedIn
            ? $database->query('SELECT id, name, cpf, email FROM employees ORDER BY id')->fetchAll()
            : [];
        foreach ($employees as &$employee) {
            $employee['id'] = (int) $employee['id'];
            $employee['cpf'] = formatCpf($employee['cpf']);
        }
        unset($employee);

        $hasAccounts = (int) $database->query('SELECT COUNT(*) FROM employees WHERE password_hash IS NOT NULL')->fetchColumn() > 0;
        respond(200, [
            'products' => $products,
            'employees' => $employees,
            'user' => $loggedIn ? ['name' => $_SESSION['employee_name']] : null,
            'canRegister' => !$hasAccounts,
        ]);
    }

    if ($method === 'POST') {
        $isForm = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
        $body = $isForm ? $_POST : readJsonBody();
        $type = $body['type'] ?? '';

        if ($type === 'login') {
            $email = trim((string) ($body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $find = $database->prepare('SELECT id, name, password_hash FROM employees WHERE email = ? AND password_hash IS NOT NULL');
            $find->execute([$email]);
            foreach ($find->fetchAll() as $row) {
                if (password_verify($password, $row['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['employee_id'] = (int) $row['id'];
                    $_SESSION['employee_name'] = $row['name'];
                    respond(200, ['name' => $row['name']]);
                }
            }
            respond(401, ['error' => 'E-mail ou senha incorretos.']);
        }

        if ($type === 'logout') {
            $_SESSION = [];
            session_destroy();
            respond(200, ['ok' => true]);
        }

        if ($type === 'product') {
            requireLogin();
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
            $newId = (int) $database->lastInsertId();
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $imagePath = saveImage($_FILES['image']);
                $database->prepare('UPDATE products SET image_path = ? WHERE id = ?')->execute([$imagePath, $newId]);
            }
            respond(201, ['id' => $newId]);
        }

        if ($type === 'employee') {
            $hasAccounts = (int) $database->query('SELECT COUNT(*) FROM employees WHERE password_hash IS NOT NULL')->fetchColumn() > 0;
            if ($hasAccounts) {
                requireLogin();
            }
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

            $password = (string) ($body['password'] ?? '');
            if (strlen($password) < 8 || strlen($password) > 72) {
                respond(422, ['error' => 'A senha precisa ter de 8 a 72 caracteres.']);
            }
            $insert = $database->prepare('INSERT INTO employees (name, cpf, email, password_hash) VALUES (?, ?, ?, ?)');
            $insert->execute([$name, $cpf, $email, password_hash($password, PASSWORD_DEFAULT)]);
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

        requireLogin();
        if ($type === 'employee' && $id === ($_SESSION['employee_id'] ?? 0)) {
            respond(409, ['error' => 'Você não pode excluir a própria conta.']);
        }
        if ($type === 'product') {
            $imageRow = $database->prepare('SELECT image_path FROM products WHERE id = ?');
            $imageRow->execute([$id]);
            $oldImage = $imageRow->fetchColumn();
        }
        $table = $type === 'product' ? 'products' : 'employees';
        $delete = $database->prepare("DELETE FROM $table WHERE id = ?");
        $delete->execute([$id]);
        if ($delete->rowCount() === 0) {
            respond(404, ['error' => 'Nenhum registro correspondente foi localizado.']);
        }

        if (!empty($oldImage) && str_starts_with($oldImage, 'uploads/') && is_file(__DIR__ . DIRECTORY_SEPARATOR . $oldImage)) {
            unlink(__DIR__ . DIRECTORY_SEPARATOR . $oldImage);
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