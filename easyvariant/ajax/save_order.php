<?php
/**
 * Save Attribute Order AJAX Endpoint
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 1.0.6-EXTENDED
 */

// Headers
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json; charset=utf-8');

// Configuration des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Constantes Dolibarr
if (!defined('NOREQUIREUSER')) define('NOREQUIREUSER', '1');
if (!defined('NOREQUIREDB')) define('NOREQUIREDB', '1');
if (!defined('NOREQUIRESOC')) define('NOREQUIRESOC', '1');
if (!defined('NOREQUIRETRAN')) define('NOREQUIRETRAN', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');

// Variables d'environnement
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Inclusion Dolibarr
$inclusion_path = '../../../main.inc.php';

try {
    $res = include_once $inclusion_path;
    
    if (!$res || !defined('DOL_DOCUMENT_ROOT')) {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'message' => 'Failed to include main.inc.php',
        ), JSON_PRETTY_PRINT);
        exit;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'message' => 'Exception during inclusion: ' . $e->getMessage(),
    ), JSON_PRETTY_PRINT);
    exit;
}

// Initialize response
$response = array(
    'success' => false,
    'message' => '',
    'version' => '1.0.6-EXTENDED'
);

// Vérifier les permissions
if (!$user->admin && empty($user->rights->produit->creer)) {
    $response['message'] = 'Insufficient permissions';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Get parameters
$attributeOrder = GETPOST('order', 'array');

if (empty($attributeOrder)) {
    $response['message'] = 'No order data provided';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Validate input
$validOrder = array();
foreach ($attributeOrder as $item) {
    $id = intval($item['id'] ?? 0);
    $position = intval($item['position'] ?? 0);
    
    if ($id > 0 && $position > 0) {
        $validOrder[] = array('id' => $id, 'position' => $position);
    }
}

if (empty($validOrder)) {
    $response['message'] = 'Invalid order data';
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Update database
try {
    $db->begin();
    
    $updated = 0;
    foreach ($validOrder as $item) {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "product_attribute 
                SET position = " . intval($item['position']) . "
                WHERE rowid = " . intval($item['id']);
        
        $result = $db->query($sql);
        if ($result) {
            $updated++;
        } else {
            throw new Exception('Failed to update attribute ' . $item['id']);
        }
    }
    
    $db->commit();
    
    $response['success'] = true;
    $response['message'] = 'Order updated successfully';
    $response['updated'] = $updated;
    
} catch (Exception $e) {
    $db->rollback();
    $response['message'] = 'Database error: ' . $e->getMessage();
}

// Return response
echo json_encode($response, JSON_PRETTY_PRINT);
?>