<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(bool $ok, string $message = '', array $data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireLogin(): array {
    if (empty($_SESSION['user'])) respond(false, 'Sessão não autenticada.', [], 401);
    return $_SESSION['user'];
}

function requireAdmin(): array {
    $user = requireLogin();
    if (($user['tipo'] ?? '') !== 'admin') respond(false, 'Acesso administrativo negado.', [], 403);
    return $user;
}

function cleanEmail(mixed $email): string {
    return strtolower(trim((string)$email));
}

function validFototipo(mixed $value): bool {
    return is_numeric($value) && (int)$value >= 1 && (int)$value <= 6;
}

function publicUser(array $u): array {
    return [
        'id' => (int)$u['id'],
        'nome' => $u['nome'],
        'email' => $u['email'],
        'tipo' => $u['tipo'],
        'idade' => isset($u['idade']) ? (int)$u['idade'] : null,
        'cidade' => $u['cidade'] ?? null,
        'sensibilidade' => $u['sensibilidade'] ?? null,
        'fototipo' => isset($u['fototipo']) ? (int)$u['fototipo'] : null,
        'criadoEm' => $u['criado_em'] ?? null,
    ];
}

function currentUser(): array {
    $session = requireLogin();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT u.id, u.nome, u.email, u.tipo, u.idade, u.cidade, p.sensibilidade, p.fototipo
                           FROM usuarios u
                           LEFT JOIN perfis p ON p.usuario_id = u.id
                           WHERE u.id = ?');
    $stmt->execute([(int)$session['id']]);
    $u = $stmt->fetch();
    if (!$u) {
        session_destroy();
        respond(false, 'Usuário não encontrado.', [], 401);
    }
    return $u;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = db();

    if ($action === 'register' && $method === 'POST') {
        $d = input();
        $nome = trim((string)($d['nome'] ?? ''));
        $email = cleanEmail($d['email'] ?? '');
        $idade = (int)($d['idade'] ?? 0);
        $cidade = trim((string)($d['cidade'] ?? ''));
        $sensibilidade = trim((string)($d['sensibilidade'] ?? 'media'));
        $fototipo = (int)($d['fototipo'] ?? 0);
        $senha = (string)($d['senha'] ?? '');

        if ($nome === '') respond(false, 'Informe seu nome.', [], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, 'Informe um e-mail válido.', [], 422);
        if ($idade < 1 || $idade > 120) respond(false, 'Informe uma idade válida.', [], 422);
        if ($cidade === '') respond(false, 'Informe sua cidade.', [], 422);
        if (!in_array($sensibilidade, ['baixa', 'media', 'alta'], true)) respond(false, 'Sensibilidade inválida.', [], 422);
        if (!validFototipo($fototipo)) respond(false, 'Fototipo inválido.', [], 422);
        if (strlen($senha) < 6) respond(false, 'A senha deve ter ao menos 6 caracteres.', [], 422);

        $check = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) respond(false, 'Este e-mail já está cadastrado.', [], 409);

        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, tipo, idade, cidade, senha_hash) VALUES (?, ?, \'usuario\', ?, ?, ?)');
            $stmt->execute([$nome, $email, $idade, $cidade, $hash]);
            $userId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO perfis (usuario_id, fototipo, sensibilidade) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $fototipo, $sensibilidade]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(true, 'Conta criada com sucesso.');
    }

    if ($action === 'login' && $method === 'POST') {
        $d = input();
        $email = cleanEmail($d['email'] ?? '');
        $senha = (string)($d['senha'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') respond(false, 'E-mail ou senha incorretos.', [], 401);

        $stmt = $pdo->prepare('SELECT u.id, u.nome, u.email, u.tipo, u.idade, u.cidade, u.senha_hash, p.sensibilidade, p.fototipo
                               FROM usuarios u LEFT JOIN perfis p ON p.usuario_id = u.id WHERE u.email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($senha, $u['senha_hash'])) respond(false, 'E-mail ou senha incorretos.', [], 401);

        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'tipo' => $u['tipo'], 'email' => $u['email']];
        unset($u['senha_hash']);
        respond(true, 'Login realizado.', ['user' => publicUser($u)]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(true, 'Sessão encerrada.');
    }

    if ($action === 'me' && $method === 'GET') {
        $u = currentUser();
        respond(true, 'Sessão ativa.', ['user' => publicUser($u)]);
    }

    if ($action === 'exposures' && $method === 'GET') {
        $u = currentUser();
        $stmt = $pdo->prepare('SELECT id, data_exposicao, duracao_segundos, indice_uv, vitamina_d_estimada, latitude, longitude, local_nome
                               FROM exposicoes WHERE usuario_id = ? ORDER BY data_exposicao ASC, id ASC');
        $stmt->execute([(int)$u['id']]);
        $rows = [];
        foreach ($stmt as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'dataISO' => $r['data_exposicao'],
                'data' => (new DateTime($r['data_exposicao']))->format('d/m/Y H:i'),
                'duracao' => (int)$r['duracao_segundos'],
                'uv' => $r['indice_uv'] !== null ? (float)$r['indice_uv'] : 0,
                'vitD' => $r['vitamina_d_estimada'] !== null ? (float)$r['vitamina_d_estimada'] : 0,
                'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
                'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
                'localNome' => $r['local_nome'],
            ];
        }
        respond(true, '', ['exposicoes' => $rows]);
    }

    if ($action === 'exposures' && $method === 'POST') {
        $u = currentUser();
        $d = input();
        $duracao = max(0, (int)($d['duracao'] ?? 0));
        $uv = max(0, (float)($d['uv'] ?? 0));
        $vitD = max(0, (float)($d['vitD'] ?? 0));
        $lat = isset($d['latitude']) && $d['latitude'] !== null ? (float)$d['latitude'] : null;
        $lon = isset($d['longitude']) && $d['longitude'] !== null ? (float)$d['longitude'] : null;
        $local = trim((string)($d['localNome'] ?? '')) ?: null;
        $dataISO = trim((string)($d['dataISO'] ?? ''));

        $data = new DateTime();
        if ($dataISO !== '') {
            try { $data = new DateTime($dataISO); } catch (Throwable $e) { $data = new DateTime(); }
        }
        $stmt = $pdo->prepare('INSERT INTO exposicoes (usuario_id, data_exposicao, duracao_segundos, indice_uv, vitamina_d_estimada, latitude, longitude, local_nome)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$u['id'], $data->format('Y-m-d H:i:s'), $duracao, $uv, $vitD, $lat, $lon, $local]);
        respond(true, 'Exposição salva.', ['id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'exposures' && $method === 'DELETE') {
        $u = currentUser();
        $stmt = $pdo->prepare('DELETE FROM exposicoes WHERE usuario_id = ?');
        $stmt->execute([(int)$u['id']]);
        respond(true, 'Histórico apagado.');
    }

    if ($action === 'admin_users' && $method === 'GET') {
        requireAdmin();
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = $pdo->prepare('SELECT u.id, u.nome, u.email, u.tipo, u.idade, u.cidade, u.criado_em, p.sensibilidade, p.fototipo,
                                          (SELECT COUNT(*) FROM exposicoes e WHERE e.usuario_id = u.id) AS total_exposicoes
                                   FROM usuarios u LEFT JOIN perfis p ON p.usuario_id = u.id
                                   WHERE u.nome LIKE ? OR u.email LIKE ? OR u.cidade LIKE ? ORDER BY u.criado_em DESC');
            $stmt->execute([$like, $like, $like]);
        } else {
            $stmt = $pdo->query('SELECT u.id, u.nome, u.email, u.tipo, u.idade, u.cidade, u.criado_em, p.sensibilidade, p.fototipo,
                                        (SELECT COUNT(*) FROM exposicoes e WHERE e.usuario_id = u.id) AS total_exposicoes
                                 FROM usuarios u LEFT JOIN perfis p ON p.usuario_id = u.id ORDER BY u.criado_em DESC');
        }
        $users = [];
        foreach ($stmt as $u) $users[] = publicUser($u) + ['totalExposicoes' => (int)$u['total_exposicoes']];
        respond(true, '', ['usuarios' => $users]);
    }

    if ($action === 'admin_history' && $method === 'GET') {
        requireAdmin();
        $id = (int)($_GET['usuario_id'] ?? 0);
        if ($id < 1) respond(false, 'Usuário inválido.', [], 422);
        $stmt = $pdo->prepare('SELECT id, data_exposicao, duracao_segundos, indice_uv, vitamina_d_estimada, latitude, longitude, local_nome
                               FROM exposicoes WHERE usuario_id = ? ORDER BY data_exposicao DESC, id DESC');
        $stmt->execute([$id]);
        $rows = [];
        foreach ($stmt as $r) $rows[] = [
            'id' => (int)$r['id'], 'dataISO' => $r['data_exposicao'],
            'data' => (new DateTime($r['data_exposicao']))->format('d/m/Y H:i'),
            'duracao' => (int)$r['duracao_segundos'], 'uv' => (float)($r['indice_uv'] ?? 0),
            'vitD' => (float)($r['vitamina_d_estimada'] ?? 0), 'latitude' => $r['latitude'],
            'longitude' => $r['longitude'], 'localNome' => $r['local_nome']
        ];
        respond(true, '', ['exposicoes' => $rows]);
    }

    if ($action === 'admin_delete_user' && $method === 'DELETE') {
        $admin = requireAdmin();
        $id = (int)($_GET['usuario_id'] ?? 0);
        if ($id < 1) respond(false, 'Usuário inválido.', [], 422);
        if ($id === (int)$admin['id']) respond(false, 'O administrador logado não pode excluir a própria conta por este painel.', [], 400);

        $stmt = $pdo->prepare('SELECT tipo FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) respond(false, 'Usuário não encontrado.', [], 404);
        if (($target['tipo'] ?? '') === 'admin') respond(false, 'Contas administrativas não podem ser excluídas por este painel.', [], 403);

        $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        respond(true, 'Conta excluída com sucesso.');
    }

    respond(false, 'Ação não encontrada.', [], 404);
} catch (PDOException $e) {
    error_log('SunSafe PDO: ' . $e->getMessage());
    respond(false, 'Erro ao acessar o banco de dados.', [], 500);
} catch (Throwable $e) {
    error_log('SunSafe API: ' . $e->getMessage());
    respond(false, 'Erro interno do servidor.', [], 500);
}
