<?php
// ============================================================
//  index.php  -  Backend Veterinaria + MongoDB (PHP)
//  Este archivo maneja todas las operaciones CRUD hacia MongoDB
//  Requiere: ext-mongodb (pecl) y mongodb/mongodb via Composer
// ============================================================

// Indica al cliente que la respuesta será JSON (tipo de contenido)
header('Content-Type: application/json');

// Permite peticiones desde cualquier origen (necesario para AJAX desde el frontend)
header('Access-Control-Allow-Origin: *');

// Permite los métodos HTTP que usará el frontend
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Permite el encabezado Content-Type en las peticiones CORS preflight
header('Access-Control-Allow-Headers: Content-Type');

// ---------- Manejo de peticiones Preflight CORS ----------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Los navegadores envían OPTIONS antes de peticiones POST entre dominios
    http_response_code(200); // Responde con OK para aprobar el preflight
    exit; // Termina la ejecución, no hay más que hacer en una petición OPTIONS
}

// ---------- Carga del Autoloader de Composer ----------
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Verifica si el archivo de autoload de Composer existe en el directorio padre
    require_once __DIR__ . '/../vendor/autoload.php';
    // Carga el autoloader que registra todas las clases de las dependencias instaladas
} else {
    // Si no existe vendor/autoload.php, significa que no se ejecutó "composer install"
    echo json_encode(['error' => 'vendor/autoload.php no encontrado. Ejecuta: composer require mongodb/mongodb']);
    exit; // Detiene la ejecución porque sin el driver de MongoDB no se puede continuar
}

// ============================================================
//  CONSTANTES DE CONFIGURACIÓN DE CONEXIÓN
// ============================================================
define('MONGO_URI', 'mongodb+srv://Kevin_Contreras:qaws120987@cluster0.llk4xoi.mongodb.net/?appName=Cluster0');
// URI de conexión a MongoDB Atlas (nube); incluye usuario, contraseña y cluster

define('MONGO_DB', 'veterinaria');
// Nombre de la base de datos dentro del cluster de MongoDB

// ============================================================
//  LISTA BLANCA DE COLECCIONES PERMITIDAS
// ============================================================
$colecciones_permitidas = [
    'clientes',              // Datos básicos de clientes/dueños
    'mascotas',              // Datos básicos de mascotas
    'mascotas_detalle',      // Información adicional de mascotas
    'veterinarios',          // Datos básicos de veterinarios
    'veterinarios_detalle',  // Información adicional de veterinarios
    'citas',                 // Citas médicas registradas
    'citas_detalle',         // Información detallada de citas
    'tratamientos',          // Tratamientos médicos
    'tratamientos_detalle',  // Información adicional de tratamientos
    'expedientes_clinicos',  // Expedientes clínicos de pacientes
    'expedientes',           // Alias alternativo para expedientes
    'duenos',                // Información de dueños de mascotas
];
// Esta lista evita que usuarios maliciosos accedan a colecciones no autorizadas

// ============================================================
//  FUNCIÓN DE CONEXIÓN A MONGODB
// ============================================================
function conectar() {
    // Crea y retorna una instancia de la base de datos MongoDB

    $client = new MongoDB\Client(MONGO_URI);
    // Crea el cliente MongoDB usando la URI de conexión definida como constante
    // MongoDB\Client gestiona la conexión al servidor (o cluster en la nube)

    return $client->{MONGO_DB};
    // Accede a la base de datos 'veterinaria' y la retorna
    // La sintaxis {MONGO_DB} permite usar una constante como nombre de propiedad
}

// ============================================================
//  FUNCIONES HELPER PARA RESPUESTAS JSON
// ============================================================
function respuesta_ok($mensaje = 'OK') {
    // Envía una respuesta JSON de éxito al cliente

    echo json_encode(['exito' => true, 'mensaje' => $mensaje]);
    // 'exito' => true indica al JavaScript que la operación fue exitosa
}

function respuesta_error($mensaje) {
    // Envía una respuesta JSON de error al cliente

    echo json_encode(['exito' => false, 'error' => $mensaje]);
    // 'exito' => false con el mensaje de error para que JS muestre el problema
}

function cursor_a_array($cursor) {
    // Convierte el cursor de MongoDB (resultado de find) en un arreglo PHP serializable

    $resultado = [];
    // Arreglo donde se almacenarán los documentos convertidos

    foreach ($cursor as $doc) {
        // Itera sobre cada documento BSON retornado por MongoDB

        $fila = [];
        // Arreglo asociativo para representar un documento como fila

        foreach ($doc as $clave => $valor) {
            // Itera sobre cada campo del documento MongoDB

            if ($clave === '_id') {
                $fila['_id'] = (string) $valor;
                // El _id de MongoDB es un objeto ObjectId; se convierte a string para JSON
            } elseif ($valor instanceof MongoDB\BSON\UTCDateTime) {
                $fila[$clave] = $valor->toDateTime()->format('Y-m-d');
                // Los campos de fecha se convierten al formato "YYYY-MM-DD" legible
            } elseif (is_object($valor) || is_array($valor)) {
                $fila[$clave] = json_encode($valor);
                // Objetos anidados o arreglos se serializan a JSON string
            } else {
                $fila[$clave] = $valor;
                // Valores escalares (strings, números, booleanos) se copian tal cual
            }
        }

        $resultado[] = $fila;
        // Agrega la fila procesada al arreglo de resultados
    }

    return $resultado;
    // Retorna el arreglo completo de documentos convertidos
}

// ============================================================
//  ENRUTAMIENTO: leer parámetros de la petición
// ============================================================
$accion    = isset($_GET['accion'])    ? trim($_GET['accion'])    : '';
// Lee el parámetro 'accion' de la URL (?accion=listar); vacío si no existe

$coleccion = isset($_GET['coleccion']) ? trim($_GET['coleccion']) : '';
// Lee el parámetro 'coleccion' de la URL (?coleccion=clientes); vacío si no existe

$id        = isset($_GET['id'])        ? trim($_GET['id'])        : '';
// Lee el parámetro 'id' de la URL (?id=abc123); es el _id de MongoDB para UPDATE/DELETE

// Normalización: el formulario de expedientes usa 'expedientes' pero la colección real es 'expedientes_clinicos'
if ($coleccion === 'expedientes') {
    $coleccion = 'expedientes_clinicos';
    // Traduce el alias del formulario al nombre real de la colección en MongoDB
}

// Validación de colección permitida (solo si hay una acción que la requiere)
if ($accion !== '' && !in_array($coleccion, $colecciones_permitidas)) {
    respuesta_error("Colección '$coleccion' no permitida.");
    // Rechaza peticiones a colecciones que no están en la lista blanca
    exit;
}

// ============================================================
//  ACCIÓN: listar — READ (GET)
// ============================================================
if ($accion === 'listar' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Solo se ejecuta si la acción es 'listar' y el método HTTP es GET

    try {
        $db = conectar();
        // Obtiene la instancia de la base de datos MongoDB

        $cursor = $db->{$coleccion}->find([], []);
        // Ejecuta find() sin filtros ni opciones para obtener TODOS los documentos
        // El primer [] es el filtro (vacío = sin filtros)
        // El segundo [] son las opciones adicionales (proyección, límite, etc.)

        $datos = cursor_a_array($cursor);
        // Convierte el cursor BSON a un arreglo PHP serializable a JSON

        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        // Envía los datos como JSON; JSON_UNESCAPED_UNICODE preserva caracteres como ñ, á, é

    } catch (Exception $e) {
        respuesta_error('Error al listar: ' . $e->getMessage());
        // Si ocurre cualquier excepción (conexión fallida, timeout, etc.), la reporta
    }
    exit; // Termina la ejecución para evitar que se procese otra acción
}

// ============================================================
//  ACCIÓN: insertar — CREATE (POST)
// ============================================================
if ($accion === 'insertar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo se ejecuta si la acción es 'insertar' y el método HTTP es POST

    $body = file_get_contents('php://input');
    // Lee el cuerpo crudo de la petición HTTP (donde va el JSON enviado por el frontend)

    $datos = json_decode($body, true);
    // Decodifica el JSON a un arreglo asociativo PHP (true = arreglo, no objeto)

    if (!$datos || !is_array($datos)) {
        respuesta_error('JSON inválido o vacío en el cuerpo de la petición.');
        exit; // Si no hay datos válidos, no se puede insertar
    }

    // Limpiar campos vacíos: MongoDB no debe guardar campos sin valor
    foreach ($datos as $k => $v) {
        if ($v === '' || $v === null) unset($datos[$k]);
        // Elimina del arreglo los campos cuyo valor sea cadena vacía o null
    }

    // Convertir campos específicos a tipo numérico para MongoDB
    foreach (['Edad', 'Precio', 'Costo'] as $campo) {
        if (isset($datos[$campo])) $datos[$campo] = floatval($datos[$campo]);
        // floatval() convierte el string a número decimal (float)
        // MongoDB almacenará estos como números, no como texto
    }

    $errores = validar_datos($coleccion, $datos);
    // Ejecuta las validaciones específicas según la colección destino

    if ($errores) {
        respuesta_error($errores);
        exit; // Si hay errores de validación, los devuelve y detiene la inserción
    }

    try {
        $db = conectar();
        // Conecta a la base de datos

        $resultado = $db->{$coleccion}->insertOne($datos);
        // insertOne() inserta un solo documento en la colección indicada
        // Retorna un objeto con información sobre la inserción

        if ($resultado->getInsertedCount() > 0) {
            // getInsertedCount() retorna 1 si se insertó correctamente, 0 si no
            respuesta_ok('Documento insertado con ID: ' . (string) $resultado->getInsertedId());
            // getInsertedId() retorna el _id generado por MongoDB para el nuevo documento
        } else {
            respuesta_error('No se insertó ningún documento.');
            // Caso raro donde no hubo error pero tampoco inserción
        }

    } catch (Exception $e) {
        respuesta_error('Error al insertar: ' . $e->getMessage());
        // Captura errores de conexión, duplicados de clave, etc.
    }
    exit;
}

// ============================================================
//  ACCIÓN: actualizar — UPDATE (POST)
// ============================================================
if ($accion === 'actualizar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo se ejecuta si la acción es 'actualizar' y el método es POST

    if (empty($id)) {
        respuesta_error('Se requiere el parámetro id para actualizar.');
        exit; // Sin el _id no se puede identificar qué documento actualizar
    }

    $body  = file_get_contents('php://input');
    // Lee el cuerpo de la petición con los datos actualizados

    $datos = json_decode($body, true);
    // Decodifica el JSON recibido

    if (!$datos || !is_array($datos)) {
        respuesta_error('JSON inválido o vacío en el cuerpo de la petición.');
        exit;
    }

    unset($datos['_id']);
    // Elimina el campo _id del payload: MongoDB no permite modificar el _id de un documento
    // Intentar actualizarlo generaría un error

    // Limpiar campos vacíos antes de actualizar
    foreach ($datos as $k => $v) {
        if ($v === '' || $v === null) unset($datos[$k]);
        // No actualiza campos que vengan vacíos (los preserva en MongoDB tal como estaban)
    }

    // Convertir campos numéricos
    foreach (['Edad', 'Precio', 'Costo'] as $campo) {
        if (isset($datos[$campo])) $datos[$campo] = floatval($datos[$campo]);
        // Igual que en insertar: garantiza que los números se guarden como tipo numérico
    }

    try {
        $db = conectar();
        // Conecta a la base de datos

        $objectId = new MongoDB\BSON\ObjectId($id);
        // Convierte el string del _id a un objeto ObjectId de MongoDB
        // MongoDB almacena los IDs como tipo ObjectId, no como string

        $resultado = $db->{$coleccion}->updateOne(
            ['_id' => $objectId],
            // Filtro: encuentra el documento cuyo _id coincide
            ['$set' => $datos]
            // Operador $set actualiza SOLO los campos especificados, sin tocar los demás
        );

        if ($resultado->getMatchedCount() > 0) {
            // getMatchedCount() > 0 significa que se encontró el documento
            respuesta_ok('Documento actualizado correctamente.');
        } else {
            respuesta_error('No se encontró el documento con ese ID.');
            // El _id no corresponde a ningún documento en la colección
        }

    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        respuesta_error('ID de documento inválido: ' . $e->getMessage());
        // Se lanza si el string del _id no tiene el formato válido de ObjectId (24 chars hex)

    } catch (Exception $e) {
        respuesta_error('Error al actualizar: ' . $e->getMessage());
        // Captura cualquier otro error de MongoDB o PHP
    }
    exit;
}

// ============================================================
//  ACCIÓN: eliminar — DELETE (POST)
// ============================================================
if ($accion === 'eliminar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Solo se ejecuta si la acción es 'eliminar' y el método es POST

    if (empty($id)) {
        respuesta_error('Se requiere el parámetro id para eliminar.');
        exit; // Sin el _id no se puede identificar qué documento borrar
    }

    try {
        $db = conectar();
        // Conecta a la base de datos

        $objectId = new MongoDB\BSON\ObjectId($id);
        // Convierte el string del _id al tipo ObjectId que MongoDB espera

        $resultado = $db->{$coleccion}->deleteOne(['_id' => $objectId]);
        // deleteOne() elimina el primer documento que coincida con el filtro
        // El filtro busca por _id, que es único, garantizando borrar solo ese documento

        if ($resultado->getDeletedCount() > 0) {
            // getDeletedCount() > 0 confirma que efectivamente se eliminó un documento
            respuesta_ok('Documento eliminado correctamente.');
        } else {
            respuesta_error('No se encontró el documento con ese ID.');
            // El _id no corresponde a ningún documento existente
        }

    } catch (MongoDB\Driver\Exception\InvalidArgumentException $e) {
        respuesta_error('ID de documento inválido: ' . $e->getMessage());
        // El string del _id tiene un formato inválido para ObjectId

    } catch (Exception $e) {
        respuesta_error('Error al eliminar: ' . $e->getMessage());
        // Cualquier otro error durante la eliminación
    }
    exit;
}

// ============================================================
//  ACCIÓN NO RECONOCIDA
// ============================================================
echo json_encode(['error' => "Acción '$accion' no reconocida o método HTTP incorrecto."]);
// Si la petición llegó con una acción que no existe o método HTTP incorrecto
exit;

// ============================================================
//  FUNCIÓN DE VALIDACIÓN POR COLECCIÓN
// ============================================================
function validar_datos($coleccion, $datos) {
    // Valida que los campos obligatorios estén presentes según la colección destino
    // Retorna un string con el mensaje de error, o null si todo está bien

    switch ($coleccion) {
        // Cada case verifica los campos mínimos requeridos para esa colección

        case 'clientes':
            if (empty($datos['ID_Cliente'])) return 'ID_Cliente es obligatorio.';
            // empty() retorna true si el campo no existe o está vacío
            if (empty($datos['Nombre']))     return 'Nombre es obligatorio.';
            break;

        case 'mascotas':
        case 'mascotas_detalle':
            // Ambas colecciones comparten la validación del ID_Mascota
            if (empty($datos['ID_Mascota'])) return 'ID_Mascota es obligatorio.';
            if (empty($datos['Nombre']) && $coleccion === 'mascotas') return 'Nombre es obligatorio.';
            // Nombre solo es obligatorio en 'mascotas', no en 'mascotas_detalle'
            break;

        case 'veterinarios':
        case 'veterinarios_detalle':
            // Ambas colecciones requieren el ID y Nombre del veterinario
            if (empty($datos['ID_Veterinario'])) return 'ID_Veterinario es obligatorio.';
            if (empty($datos['Nombre']))          return 'Nombre es obligatorio.';
            break;

        case 'citas':
        case 'citas_detalle':
            // Solo el ID de la cita es obligatorio
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
    // Retorna null si todas las validaciones pasaron exitosamente
    // null es "falsy" en PHP, por eso en el código principal se evalúa con if ($errores)
}
?>
