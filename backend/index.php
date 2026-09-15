<?php
require '/var/www/html/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Reintenta la conexión unas cuantas veces por si MySQL aún no terminó
// de inicializar cuando arranca el contenedor del backend.
$conn = null;
$intentos = 10;

for ($i = 1; $i <= $intentos; $i++) {
    $conn = @new mysqli(
        getenv("DB_HOST"),
        getenv("DB_USER"),
        getenv("DB_PASS"),
        getenv("DB_NAME")
    );

    if (!$conn->connect_error) {
        break;
    }

    if ($i === $intentos) {
        echo json_encode(["error" => "No se pudo conectar a la base de datos: " . $conn->connect_error]);
        exit;
    }

    sleep(2);
}

// --- Configuración de Amazon S3 ---
// Las credenciales y el bucket se leen de variables de entorno
// (definidas en docker-compose.yml / .env), nunca hardcodeadas en el código.
$AWS_BUCKET = getenv('AWS_BUCKET_NAME');
$AWS_REGION = getenv('AWS_REGION');

$s3 = new S3Client([
    'version'     => 'latest',
    'region'      => $AWS_REGION,
    'credentials' => [
        'key'    => getenv('AWS_ACCESS_KEY_ID'),
        'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
    ],
]);

$MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB
$EXTENSIONES_PERMITIDAS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// Construye la URL pública de un objeto en el bucket (bucket configurado como público)
function urlImagenS3($key) {
    global $s3, $AWS_BUCKET;
    if (!$key) return null;
    return $s3->getObjectUrl($AWS_BUCKET, $key);
}

// Sube $_FILES['imagen'] a S3 si viene en la petición.
// Devuelve el "key" (ruta) guardado en el bucket, o null si no se envió imagen.
// Si hay un error, lo deja en $error (por referencia) y devuelve false.
function subirImagenAS3(&$error) {
    global $s3, $AWS_BUCKET, $MAX_UPLOAD_BYTES, $EXTENSIONES_PERMITIDAS;

    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // imagen opcional
    }

    $archivo = $_FILES['imagen'];

    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = "Error al subir la imagen (código {$archivo['error']})";
        return false;
    }

    if ($archivo['size'] > $MAX_UPLOAD_BYTES) {
        $error = "La imagen supera el tamaño máximo permitido (5MB)";
        return false;
    }

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $EXTENSIONES_PERMITIDAS, true)) {
        $error = "Formato de imagen no permitido. Usa: " . implode(', ', $EXTENSIONES_PERMITIDAS);
        return false;
    }

    // Verifica que el archivo realmente sea una imagen (no solo la extensión)
    $infoImagen = @getimagesize($archivo['tmp_name']);
    if ($infoImagen === false) {
        $error = "El archivo no es una imagen válida";
        return false;
    }

    $mimePorExtension = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    $key = 'productos/' . bin2hex(random_bytes(16)) . '.' . $extension;

    try {
        // No se envía 'ACL' => 'public-read': desde 2023 los buckets nuevos
        // de S3 tienen "ACLs disabled" (Bucket owner enforced) por defecto,
        // y ese parámetro haría fallar la subida con AccessControlListNotSupported.
        // La lectura pública queda a cargo únicamente de la Bucket Policy
        // (ver README, sección 1.2).
        $s3->putObject([
            'Bucket'      => $AWS_BUCKET,
            'Key'         => $key,
            'SourceFile'  => $archivo['tmp_name'],
            'ContentType' => $mimePorExtension[$extension],
        ]);
    } catch (AwsException $e) {
        $error = "No se pudo subir la imagen a S3: " . $e->getAwsErrorMessage();
        return false;
    }

    return $key;
}

// Borra un objeto del bucket dado su key
function borrarImagenS3($key) {
    global $s3, $AWS_BUCKET;
    if (!$key) return;
    try {
        $s3->deleteObject(['Bucket' => $AWS_BUCKET, 'Key' => $key]);
    } catch (AwsException $e) {
        // Si falla el borrado en S3 no detenemos el flujo, solo lo ignoramos.
    }
}

$action = $_GET['action'] ?? 'list';

switch ($action) {

    // Agrega un iPhone nuevo al catálogo (con imagen opcional subida a S3)
    case 'add':
        $modelo = trim($_POST['modelo'] ?? '');
        $precio = (float) ($_POST['precio'] ?? 0);
        $stock  = (int) ($_POST['stock'] ?? 0);

        if ($modelo === '') {
            echo json_encode(["error" => "El modelo es obligatorio"]);
            exit;
        }

        $errorImagen = null;
        $keyImagen = subirImagenAS3($errorImagen);

        if ($keyImagen === false) {
            echo json_encode(["error" => $errorImagen]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO productos (modelo, precio, stock, imagen) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sdis", $modelo, $precio, $stock, $keyImagen);
        $stmt->execute();

        echo json_encode(["success" => true, "id" => $conn->insert_id]);
        break;

    // Elimina un iPhone del catálogo (y su imagen en S3 si tenía)
    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT imagen FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        if ($row && !empty($row['imagen'])) {
            borrarImagenS3($row['imagen']);
        }

        echo json_encode(["success" => true]);
        break;

    // "Compra" un iPhone: descuenta 1 unidad de stock
    case 'buy':
        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || $row['stock'] < 1) {
            echo json_encode(["error" => "Sin stock disponible"]);
            exit;
        }

        $update = $conn->prepare("UPDATE productos SET stock = stock - 1 WHERE id = ?");
        $update->bind_param("i", $id);
        $update->execute();

        echo json_encode(["success" => true]);
        break;

    // Lista todos los iPhones del catálogo, con la URL pública de S3 (acción por defecto)
    case 'list':
    default:
        $result = $conn->query("SELECT id, modelo, precio, stock, imagen FROM productos ORDER BY id");
        $productos = [];
        while ($row = $result->fetch_assoc()) {
            $row['imagen_url'] = urlImagenS3($row['imagen']);
            unset($row['imagen']);
            $productos[] = $row;
        }
        echo json_encode($productos);
        break;
}
