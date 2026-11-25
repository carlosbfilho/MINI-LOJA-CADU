# Núcleo — Loja & Equipe

Interface de gestão criada para visualizar uma seleção inicial de dez produtos, publicar novos itens e manter um quadro de colaboradores.

## Executando localmente

O ambiente precisa ter PHP 8 ou superior e a extensão PDO_SQLite ativa. Após instalar o PHP, abra novamente o terminal para atualizar as variáveis do sistema.

1. Abra o terminal na pasta atual do projeto: `Mini-Loja-main\Mini-Loja-cadu`.
2. Dentro dessa pasta, rode `php -S 127.0.0.1:8000`.
3. Abra `http://127.0.0.1:8000/Index.html` no navegador.

Os registros são persistidos em SQLite. O código localiza a pasta do projeto pelo próprio diretório e usa `../Mini-Loja-cadu-data/loja.sqlite` por padrão, fora da pasta pública. Para escolher outra localização, defina `MINI_LOJA_DB_PATH` antes de iniciar o servidor e mantenha o arquivo protegido fora do diretório publicado.

No primeiro acesso, cadastre o primeiro colaborador (com senha de 8+ caracteres) na aba **Administrar**; depois disso, só quem está logado pode incluir ou excluir itens e pessoas. Os produtos aceitam foto (JPG, PNG, WEBP ou GIF, até 2 MB), salva na pasta `uploads/`. Colaboradores antigos sem senha precisam ser recriados.

Cada colaborador precisa de nome, CPF com onze dígitos e e-mail válido. O CPF não pode ser reutilizado. Na área **Administrar**, é possível remover itens e pessoas. Todas as artes da vitrine são vetores locais, portanto o projeto não depende de imagens externas.

Este projeto foi preparado para demonstração local. Para publicação na internet, implemente autenticação e medidas adequadas de proteção dos dados pessoais.
