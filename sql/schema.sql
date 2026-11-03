-- Control de Cupones — Imperio Comercial
-- Base: c2881399_control (MySQL 8 / MariaDB 10.5+)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario       VARCHAR(50)  NOT NULL UNIQUE,
  nombre        VARCHAR(100) NOT NULL,
  pass_hash     VARCHAR(255) NOT NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  intentos      INT          NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME   NULL,
  ultimo_acceso DATETIME     NULL,
  creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cupones copiados desde la planilla de Google (se actualizan en cada sincronización)
CREATE TABLE IF NOT EXISTS cupones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           CHAR(40)      NOT NULL UNIQUE,
  zona            VARCHAR(100)  NOT NULL,
  periodo         CHAR(7)       NULL,            -- '2026-10' = año-mes
  fila_planilla   INT           NULL,
  fecha_recepcion VARCHAR(40)   NULL,
  fecha_cupon     DATE          NULL,
  hora            VARCHAR(10)   NULL,
  cobrador        VARCHAR(150)  NULL,
  titular         VARCHAR(200)  NULL,
  monto           DECIMAL(14,2) NOT NULL DEFAULT 0,
  billetera       VARCHAR(100)  NULL,
  nro_operacion   VARCHAR(100)  NULL,
  cbu_alias       VARCHAR(150)  NULL,
  cuit            VARCHAR(11)   NULL,
  dni             INT UNSIGNED  NULL,
  estado_bot      VARCHAR(60)   NULL,
  msg_id          VARCHAR(120)  NULL,
  en_planilla     TINYINT(1)    NOT NULL DEFAULT 1,
  sincronizado_en DATETIME      NOT NULL,
  -- resultado de la última conciliación
  estado_conc     VARCHAR(10)   NULL,   -- ok | revisar | no | espera | omitido
  detalle_conc    VARCHAR(200)  NULL,
  movimiento_id   INT UNSIGNED  NULL,
  INDEX idx_cup_dni (dni),
  INDEX idx_cup_fecha (fecha_cupon),
  INDEX idx_cup_monto (monto),
  INDEX idx_cup_estado (estado_conc),
  INDEX idx_cup_periodo (periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transferencias recibidas según los resúmenes del Banco Macro (historial acumulado)
CREATE TABLE IF NOT EXISTS movimientos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave       CHAR(40)      NOT NULL UNIQUE,
  fecha       DATE          NOT NULL,
  cuit        VARCHAR(11)   NULL,
  dni         INT UNSIGNED  NULL,           -- DNI de quien transfirió, según el banco (dato crudo)
  dni_efectivo INT UNSIGNED NULL,           -- DNI del cliente al que corresponde el pago (= dni si no es de un tercero)
  es_tercero  TINYINT(1)    NOT NULL DEFAULT 0,   -- 1 si pagó un tercero por el cliente
  nombre      VARCHAR(150)  NULL,
  monto       DECIMAL(14,2) NOT NULL,
  referencia  VARCHAR(50)   NULL,
  codigo      VARCHAR(20)   NULL,
  concepto    VARCHAR(255)  NOT NULL,
  control_id  INT UNSIGNED  NULL,           -- control en el que se cargó por primera vez
  cupon_id    INT UNSIGNED  NULL,           -- cupón con el que se concilió (última conciliación)
  INDEX idx_mov_dni (dni),
  INDEX idx_mov_efec (dni_efectivo),
  INDEX idx_mov_fecha (fecha),
  INDEX idx_mov_monto (monto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada vez que se sube un resumen del banco
CREATE TABLE IF NOT EXISTS controles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creado_en       DATETIME      NOT NULL,
  usuario_id      INT UNSIGNED  NOT NULL,
  archivo         VARCHAR(200)  NULL,
  desde           DATE          NULL,
  hasta           DATE          NULL,
  mov_archivo     INT           NOT NULL DEFAULT 0,
  mov_nuevos      INT           NOT NULL DEFAULT 0,
  total_archivo   DECIMAL(16,2) NOT NULL DEFAULT 0,
  ok              INT           NOT NULL DEFAULT 0,
  revisar         INT           NOT NULL DEFAULT 0,
  no_figuran      INT           NOT NULL DEFAULT 0,
  espera          INT           NOT NULL DEFAULT 0,
  sin_cupon       INT           NOT NULL DEFAULT 0,
  total_sin_cupon DECIMAL(16,2) NOT NULL DEFAULT 0,
  INDEX idx_ctrl_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foto del resultado de cada control (no cambia aunque después se reconcilie)
CREATE TABLE IF NOT EXISTS control_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  control_id    INT UNSIGNED  NOT NULL,
  tipo          VARCHAR(10)   NOT NULL,      -- cupon | sin_cupon
  estado        VARCHAR(10)   NOT NULL,      -- ok | revisar | no | espera | omitido | sin_cupon
  detalle       VARCHAR(200)  NULL,
  cupon_id      INT UNSIGNED  NULL,
  movimiento_id INT UNSIGNED  NULL,
  zona          VARCHAR(100)  NULL,
  fecha_cupon   DATE          NULL,
  cobrador      VARCHAR(150)  NULL,
  titular       VARCHAR(200)  NULL,
  monto         DECIMAL(14,2) NOT NULL DEFAULT 0,
  billetera     VARCHAR(100)  NULL,
  nro_operacion VARCHAR(100)  NULL,
  fecha_banco   DATE          NULL,
  nombre_banco  VARCHAR(200)  NULL,
  cuit_banco    VARCHAR(11)   NULL,
  dni           INT UNSIGNED  NULL,
  dni_efectivo  INT UNSIGNED  NULL,
  es_tercero    TINYINT(1)    NOT NULL DEFAULT 0,
  INDEX idx_item_control (control_id, tipo, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cupones que no figuran en el banco, para seguir hasta que se resuelvan
CREATE TABLE IF NOT EXISTS pendientes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cupon_id     INT UNSIGNED  NOT NULL UNIQUE,
  estado       VARCHAR(10)   NOT NULL DEFAULT 'pendiente',   -- pendiente | resuelto
  detectado_en DATETIME      NOT NULL,
  control_id   INT UNSIGNED  NULL,
  nota         VARCHAR(500)  NULL,
  resuelto_en  DATETIME      NULL,
  resuelto_por VARCHAR(100)  NULL,
  INDEX idx_pend_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de búsquedas
CREATE TABLE IF NOT EXISTS busquedas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  texto      VARCHAR(150) NOT NULL,
  resultados INT          NOT NULL DEFAULT 0,
  creado_en  DATETIME     NOT NULL,
  INDEX idx_busq_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ajustes (
  clave VARCHAR(50)  NOT NULL PRIMARY KEY,
  valor VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cupones "a revisar" que una persona confirmó a mano (se mantienen al volver a cruzar)
CREATE TABLE IF NOT EXISTS confirmaciones (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cupon_id         INT UNSIGNED NOT NULL UNIQUE,
  movimiento_id    INT UNSIGNED NULL,
  nota             VARCHAR(300) NULL,
  detalle_original VARCHAR(200) NULL,
  confirmado_por   VARCHAR(100) NOT NULL,
  confirmado_en    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clientes que pagan desde la cuenta de un tercero (familiar, representante):
-- el CUIL que informa el banco no es el del cliente. El mapeo lo carga una
-- persona a mano y se vuelve a aplicar en cada cruce, igual que confirmaciones.
-- `activo` se pone en NULL al desactivar: NULL no entra en el UNIQUE, así que
-- queda historial de mapeos viejos y a la vez un solo par activo por vez.
CREATE TABLE IF NOT EXISTS pagadores_terceros (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dni_tercero     INT UNSIGNED  NOT NULL,
  cuit_tercero    VARCHAR(11)   NULL,
  nombre_tercero  VARCHAR(200)  NULL,
  dni_cliente_sas INT UNSIGNED  NOT NULL,
  es_principal    TINYINT(1)    NOT NULL DEFAULT 0,
  nota            VARCHAR(300)  NULL,
  activo          TINYINT(1)    NULL DEFAULT 1,
  creado_por      VARCHAR(100)  NOT NULL,
  creado_en       DATETIME      NOT NULL,
  desactivado_por VARCHAR(100)  NULL,
  desactivado_en  DATETIME      NULL,
  UNIQUE KEY uq_terc_cli_activo (dni_tercero, dni_cliente_sas, activo),
  INDEX idx_terc_dni (dni_tercero, activo),
  INDEX idx_terc_cliente (dni_cliente_sas, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Esta transferencia puntual es de este cliente". Sirve cuando el pagador
-- paga por varios clientes y no hay cupón que lo aclare, o para alguien que
-- pagó una sola vez y no vale la pena vincularlo de forma permanente.
CREATE TABLE IF NOT EXISTS movimientos_cliente (
  movimiento_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  dni_cliente_sas INT UNSIGNED NOT NULL,
  nota            VARCHAR(300) NULL,
  asignado_por    VARCHAR(100) NOT NULL,
  asignado_en     DATETIME     NOT NULL,
  INDEX idx_movcli_cliente (dni_cliente_sas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
