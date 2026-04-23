<?php
/**
 * StockMaster — API REST (PHP + PDO + MySQL)
 * Coloque este arquivo em: /stockmaster/api/index.php
 *
 * Configure o .htaccess (Apache) ou nginx para redirecionar
 * todas as rotas para este index.php (ver comentário no final).
 */

// ── Configuração do banco ──────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'stockmaster');
define('DB_USER', 'root');       // ← altere
define('DB_PASS', '');           // ← altere
define('DB_PORT', '3306');

// ── CORS (ajuste o origin para o domínio do seu frontend) ──
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Conexão PDO ────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                        DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── Helpers ────────────────────────────────────────────────
function json_out(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $msg, int $status = 400): void {
    json_out(['message' => $msg], $status);
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── Roteamento simples ─────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove prefixo da rota base (ajuste se necessário)
$uri = preg_replace('#^/stockmaster/api#', '', $uri);
$uri = rtrim($uri, '/') ?: '/';

$parts = explode('/', ltrim($uri, '/'));
$resource = $parts[0] ?? '';
$id       = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;

try {

    // ══════════════════════════════════════════
    //  /produtos
    // ══════════════════════════════════════════
    if ($resource === 'produtos') {

        // GET /produtos — lista todos
        if ($method === 'GET' && $id === null) {
            $rows = db()->query('SELECT * FROM vw_produtos_status ORDER BY id')->fetchAll();
            json_out($rows);
        }

        // GET /produtos/:id — busca um
        if ($method === 'GET' && $id !== null) {
            $st = db()->prepare('SELECT * FROM vw_produtos_status WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) json_error('Produto não encontrado', 404);
            json_out($row);
        }

        // POST /produtos — cria
        if ($method === 'POST') {
            $b = body();
            if (empty($b['nome'])) json_error('Campo "nome" é obrigatório');

            $st = db()->prepare('
                INSERT INTO produtos (nome, descricao, preco, quantidade, minimo)
                VALUES (:nome, :descricao, :preco, :quantidade, :minimo)
            ');
            $st->execute([
                ':nome'       => trim($b['nome']),
                ':descricao'  => $b['descricao'] ?? null,
                ':preco'      => (float)($b['preco']      ?? 0),
                ':quantidade' => (int)($b['quantidade']   ?? 0),
                ':minimo'     => (int)($b['minimo']       ?? 0),
            ]);

            $newId = (int)db()->lastInsertId();
            $st2   = db()->prepare('SELECT * FROM vw_produtos_status WHERE id = ?');
            $st2->execute([$newId]);
            json_out($st2->fetch(), 201);
        }

        // DELETE /produtos/:id — remove
        if ($method === 'DELETE' && $id !== null) {
            $st = db()->prepare('DELETE FROM produtos WHERE id = ?');
            $st->execute([$id]);
            if ($st->rowCount() === 0) json_error('Produto não encontrado', 404);
            json_out(['message' => 'Produto removido', 'id' => $id]);
        }

        json_error('Método não permitido', 405);
    }

    // ══════════════════════════════════════════
    //  /movimentacoes
    // ══════════════════════════════════════════
    if ($resource === 'movimentacoes') {

        // GET /movimentacoes — lista todas (join com nome do produto)
        if ($method === 'GET') {
            $rows = db()->query('
                SELECT m.id, m.produto_id, p.nome AS produto_nome,
                       m.tipo, m.volume, m.criado_em
                FROM movimentacoes m
                JOIN produtos p ON p.id = m.produto_id
                ORDER BY m.criado_em DESC
                LIMIT 200
            ')->fetchAll();
            json_out($rows);
        }

        // POST /movimentacoes — registra (chama stored procedure)
        if ($method === 'POST') {
            $b = body();
            if (empty($b['produto_id'])) json_error('Campo "produto_id" é obrigatório');
            if (empty($b['tipo']) || !in_array($b['tipo'], ['IN','OUT']))
                json_error('"tipo" deve ser IN ou OUT');
            if (empty($b['volume']) || (int)$b['volume'] < 1)
                json_error('"volume" deve ser um inteiro positivo');

            $pdo = db();
            $st  = $pdo->prepare('CALL sp_movimentar(:pid, :tipo, :vol, @ok)');
            $st->execute([
                ':pid'  => (int)$b['produto_id'],
                ':tipo' => $b['tipo'],
                ':vol'  => (int)$b['volume'],
            ]);
            $st->closeCursor();

            $ok = (int)$pdo->query('SELECT @ok')->fetchColumn();
            if ($ok === 0) json_error('Saldo insuficiente em estoque', 409);

            // Retorna a última movimentação inserida
            $last = $pdo->query('
                SELECT m.id, m.produto_id, p.nome AS produto_nome,
                       m.tipo, m.volume, m.criado_em
                FROM movimentacoes m
                JOIN produtos p ON p.id = m.produto_id
                ORDER BY m.id DESC LIMIT 1
            ')->fetch();

            json_out($last, 201);
        }

        json_error('Método não permitido', 405);
    }

    json_error('Rota não encontrada', 404);

} catch (PDOException $e) {
    json_error('Erro no banco de dados: ' . $e->getMessage(), 500);
}

/*
─────────────────────────────────────────────
  .htaccess para Apache (coloque em /stockmaster/api/)
─────────────────────────────────────────────
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.*)$ index.php [QSA,L]

─────────────────────────────────────────────
  nginx (dentro do server block)
─────────────────────────────────────────────
  location /stockmaster/api/ {
      try_files $uri $uri/ /stockmaster/api/index.php?$query_string;
  }
─────────────────────────────────────────────
*/