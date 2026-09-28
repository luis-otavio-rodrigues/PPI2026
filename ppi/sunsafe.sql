-- ============================================================
-- SunSafe - Banco de Dados MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS sunsafe
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sunsafe;

-- ------------------------------------------------------------
-- Usuários
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Perfil do usuário
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS perfis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL UNIQUE,
    fototipo TINYINT UNSIGNED NOT NULL,
    sensibilidade VARCHAR(30) DEFAULT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_perfis_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT chk_fototipo
        CHECK (fototipo BETWEEN 1 AND 6)
);

-- ------------------------------------------------------------
-- Registros de exposição solar
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exposicoes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    data_exposicao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duracao_segundos INT UNSIGNED NOT NULL DEFAULT 0,
    indice_uv DECIMAL(4,2) DEFAULT NULL,
    vitamina_d_estimada DECIMAL(10,2) DEFAULT NULL,

    -- Localização usada no momento do registro
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    local_nome VARCHAR(200) DEFAULT NULL,

    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_exposicoes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_exposicoes_usuario_data (usuario_id, data_exposicao)
);

-- ------------------------------------------------------------
-- Conquistas
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conquistas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    nome VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    icone VARCHAR(50) DEFAULT NULL
);

-- Conquistas obtidas por cada usuário
CREATE TABLE IF NOT EXISTS usuario_conquistas (
    usuario_id INT UNSIGNED NOT NULL,
    conquista_id INT UNSIGNED NOT NULL,
    obtida_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (usuario_id, conquista_id),

    CONSTRAINT fk_uc_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_uc_conquista
        FOREIGN KEY (conquista_id) REFERENCES conquistas(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ------------------------------------------------------------
-- Configurações do usuário
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracoes (
    usuario_id INT UNSIGNED PRIMARY KEY,
    tema ENUM('claro', 'escuro') NOT NULL DEFAULT 'claro',

    CONSTRAINT fk_config_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- ------------------------------------------------------------
-- Conquistas iniciais do SunSafe
-- Podem ser alteradas depois para combinar exatamente
-- com as conquistas existentes no index.html.
-- ------------------------------------------------------------
INSERT INTO conquistas (codigo, nome, descricao, icone)
VALUES
    ('primeira_exposicao', 'Primeira exposição', 'Registrou sua primeira exposição solar.', '☀️'),
    ('cinco_exposicoes', '5 exposições', 'Registrou cinco exposições solares.', '🌞'),
    ('dez_exposicoes', '10 exposições', 'Registrou dez exposições solares.', '🏆'),
    ('primeiro_registro_uv', 'Primeiro registro UV', 'Realizou seu primeiro registro com índice UV.', '🔆')
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    descricao = VALUES(descricao),
    icone = VALUES(icone);

-- ============================================================
-- Consultas úteis
-- ============================================================

-- Histórico de um usuário:
-- SELECT * FROM exposicoes
-- WHERE usuario_id = 1
-- ORDER BY data_exposicao DESC;

-- Total de vitamina D estimada nos últimos 7 dias:
-- SELECT COALESCE(SUM(vitamina_d_estimada), 0) AS total
-- FROM exposicoes
-- WHERE usuario_id = 1
--   AND data_exposicao >= NOW() - INTERVAL 7 DAY;

-- Total de exposições:
-- SELECT COUNT(*) AS total
-- FROM exposicoes
-- WHERE usuario_id = 1;
