<?php
// ============================================================
//  index.php  -  Backend Veterinaria + MongoDB (PHP)
//  Requiere: ext-mongodb (pecl) y mongodb/mongodb via Composer
//  Instalacion:
//    pecl install mongodb
//    composer require mongodb/mongodb
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// ---------- Preflight CORS ----------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------- Autoload Composer ----------
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    echo json_encode(['error' => 'vendor/autoload.php no encontrado. Ejecuta: composer require mongodb/mongodb']);
    exit;
}

// ============================================================
//  CONFIGURACION DE CONEXION
// ============================================================
define('MONGO_URI', 'mongodb://localhost:27017');
define('MONGO_DB',  'veterinaria');

// ============================================================
//  COLECCIONES PERMITIDAS
// ============================================================
$colecciones_permitidas = [
    'clientes',
    'mascotas',
    'mascotas_detalle',
    'veterinarios',
    'veterinarios_detalle',
    'citas',
    'citas_detalle',
    'tratamientos',
    'tratamientos_detalle',
    'expedientes_clinicos',
    'expedientes',
    'duenos',
];

// ============================================================
//  CONEXION
// ============================================================
function conectar() {
    $client = new MongoDB\Client(MONGO_URI);
    return $client->{MONGO_DB};
}

// ============================================================
//  HELPERS
// ============================================================
function respuesta_ok($mensaje = 'OK') {
    echo json_encode(['exito' => true, 'mensaje' => $mensaje]);
}

function respuesta_error($mensaje) {
    echo json_encode(['exito' => false, 'error' => $mensaje]);
}

function cursor_a_array($cursor) {
    $resultado = [];
    foreach ($cursor as $doc) {
        $fila = [];
        foreach ($doc as $clave => $valor) {
            if ($clave === '_id') {
                $fila['_id'] = (string) $valor;
            } elseif ($valor instanceof MongoDB\BSON\UTCDateTime) {
                $fila[$clave] = $valor->toDateTime()->format('Y-m-d');
            } elseif (is_object($valor) || is_array($valor)) {
                $fila[$clave] = json_encode($valor);
            } else {
                $fila[$clave] = $valor;
            }
        }
        $resultado[] = $fila;
    }
    return $resultado;
}

// ============================================================
//  ENRUTAMIENTO
// ============================================================
$accion    = isset($_GET['accion'])    ? trim($_GET['accion'])    : '';
$coleccion = isset($_GET['coleccion']) ? trim($_GET['coleccion']) : '';
$id        = isset($_GET['id'])        ? trim($_GET['id'])        : '';

// Normalizar alias del formulario 'expedientes' → colección real
if ($coleccion === 'expedientes') {
    $coleccion = 'expedientes_clinicos';
}

// Validar colección (solo cuando hay acción)
if ($accion !== '' && !in_array($coleccion, $colecciones_permitidas)) {
    respuesta_error("Colección '$coleccion' no permitida.");
    exit;
}

// ============================================================
//  ACCION: listar  (READ)
// ============================================================
if ($accion === 'listar' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $db     = conectar();
        $cursor = $db->{$coleccion}->find([], []);
        $datos  = cursor_a_array($cursor);
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        respuesta_error('Error al listar: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
//  ACCION: insertar  (CREATE)
// ============================================================
if ($accion === 'insertar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = file_get_contents('php://input');
    $datos = json_decode($body, true);

    if (!$datos || !is_array($datos)) {
        respuesta_error('JSON inválido o vacío en el cuerpo de la petición.');
        exit;
    }

    // Limpiar campos vacíos opcionales
    foreach ($datos as $k => $v) {
        if ($v === '' || $v === null) unset($datos[$k]);
    }

    // Convertir campos numéricos
    foreach (['Edad', 'Precio', 'Costo'] as $campo) {
        if (isset($datos[$campo])) $datos[$campo] = floatval($datos[$campo]);
    }

    $errores = validar_datos($coleccion, $datos);
    if ($errores) { respuesta_error($errores); exit; }

    try {
        $db        = conectar();
        $resultado = $db->{$coleccion}->insertOne($datos);

        if ($resultado->getInsertedCount() > 0) {
            respuesta_ok('Documento insertado con ID: ' . (string) $resultado->getInsertedId());
        } else {
            respuesta_error('No se insertó ningún documento.');
        }
    } catch (Exception $e) {
        respuesta_error('Error al insertar: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
//  ACCION: actualizar  (UPDATE)
// ============================================================
if ($accion === 'actualizar' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($id)) {
        respuesta_error('Se requiere el parámetro id para actualizar.');
        exit;
    }

    $body  = file_get_contents('php://input');
    $datos = json_decode($body, true);

    if (!$datos || !is_array($datos)) {
        respuesta_error('JSON inválido o vacío en el cuerpo de la petición.');
        exit;
    }

    // Eliminar _id del payload si viene (no se puede actualizar el _id)
    unset($datos['_id']);

    // Limpiar campos vacíos opcionales
    foreach ($datos as $k => $v) {
        if ($v === '' || $v === null) unset($datos[$k]);
    }

    // Convertir campos numéricos
    foreach (['Edad', 'Precio', 'Costo'] as $campo) {
        if (isset($datos[$campo])) $datos[$campo] = floatval($datos[$campo]);
    }

    try {
        $db        = conectar();
        $objectId  = new MongoDB\BSON\ObjectId($id);
        $resultado = $db->{$coleccion}->updateOne(
            ['_id' => $objectId],
            ['$set' => $datos]
        );

        if ($resultado->getMatchedCount() > 0) {
            respuesta_ok('Documento actualizado correctamente.');
        } else {
            respuesta_error('No se encontró el documento con ese ID.');
        }
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        respuesta_error('ID de documento inválido: ' . $e->getMessage());
    } catch (Exception $e) {
        respuesta_error('Error al actualizar: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
//  ACCION: eliminar  (DELETE)
// ============================================================
if ($accion === 'eliminar' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($id)) {
        respuesta_error('Se requiere el parámetro id para eliminar.');
        exit;
    }

    try {
        $db        = conectar();
        $objectId  = new MongoDB\BSON\ObjectId($id);
        $resultado = $db->{$coleccion}->deleteOne(['_id' => $objectId]);

        if ($resultado->getDeletedCount() > 0) {
            respuesta_ok('Documento eliminado correctamente.');
        } else {
            respuesta_error('No se encontró el documento con ese ID.');
        }
    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        respuesta_error('ID de documento inválido: ' . $e->getMessage());
    } catch (Exception $e) {
        respuesta_error('Error al eliminar: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
//  Acción no reconocida
// ============================================================
echo json_encode(['error' => "Acción '$accion' no reconocida o método HTTP incorrecto."]);
exit;

// ============================================================
//  VALIDACIONES POR COLECCION
// ============================================================
function validar_datos($coleccion, $datos) {
    switch ($coleccion) {
        case 'clientes':
            if (empty($datos['ID_Cliente'])) return 'ID_Cliente es obligatorio.';
            if (empty($datos['Nombre']))     return 'Nombre es obligatorio.';
            break;

        case 'mascotas':
        case 'mascotas_detalle':
            if (empty($datos['ID_Mascota'])) return 'ID_Mascota es obligatorio.';
            if (empty($datos['Nombre']) && $coleccion === 'mascotas') return 'Nombre es obligatorio.';
            break;

        case 'veterinarios':
        case 'veterinarios_detalle':
            if (empty($datos['ID_Veterinario'])) return 'ID_Veterinario es obligatorio.';
            if (empty($datos['Nombre']))          return 'Nombre es obligatorio.';
            break;

        case 'citas':
        case 'citas_detalle':
            if (empty($datos['ID_Cita'])) return 'ID_Cita es obligatorio.';
            break;

        case 'tratamientos':
        case 'tratamientos_detalle':
            if (empty($datos['ID_Tratamiento'])) return 'ID_Tratamiento es obligatorio.';
            break;

        case 'expedientes_clinicos':
            if (empty($datos['ID_Expediente'])) return 'ID_Expediente es obligatorio.';
            break;

        case 'duenos':
            if (empty($datos['ID_Dueno'])) return 'ID_Dueno es obligatorio.';
            break;
    }
    return null;
}
?>
