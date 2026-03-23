<?php
/**
 * EasyVariant Actions Class - FINAL PRODUCTION VERSION
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 1.0.5-FINAL
 */

class ActionsEasyVariant
{
    public $name = 'easyvariant';
    public $errors = array();
    private $db;
    private static $assets_loaded = false;
    private static $config_sent = false;
    private static $processed = false;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Vérifier si on doit traiter cette requête
     */
    private function shouldProcess()
    {
        $uri = $_SERVER['REQUEST_URI'];
        
        // SEULEMENT traiter la page des variantes
        if (strpos($uri, '/variants/combinations.php') === false) {
            return false;
        }
        
        // NE JAMAIS traiter les .js.php
        if (strpos($uri, '.js.php') !== false) {
            return false;
        }
        
        // NE JAMAIS traiter les requêtes AJAX
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            return false;
        }
        
        // NE JAMAIS traiter si c'est du JSON/XML
        $contentType = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (strpos($contentType, 'application/json') !== false || strpos($contentType, 'text/xml') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Hook principal - doActions
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->shouldProcess()) {
            return 0;
        }
        
        $this->processVariantPage();
        return 0;
    }
    
    /**
     * Hook printCommonFooter
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        if (!$this->shouldProcess()) {
            return 0;
        }
        
        $this->processVariantPage();
        return 0;
    }
    
    /**
     * Hook printMainArea
     */
    public function printMainArea($parameters, &$object, &$action, $hookmanager) 
    {
        if (!$this->shouldProcess()) {
            return 0;
        }
        
        $this->processVariantPage();
        return 0;
    }
    
    /**
     * Traiter la page des variantes - VERSION FINALE
     */
    private function processVariantPage()
    {
        // Éviter de traiter plusieurs fois
        if (self::$processed) {
            return;
        }
        
        // Vérifier l'environnement
        if (!defined('DOL_DOCUMENT_ROOT') || !function_exists('dol_buildpath')) {
            return;
        }
        
        self::$processed = true;
        
        // Charger les assets UNE SEULE FOIS
        if (!self::$assets_loaded) {
            echo '<link rel="stylesheet" type="text/css" href="'.dol_buildpath('/easyvariant/css/easyvariant.css', 1).'">'; 
            echo '<script src="'.dol_buildpath('/easyvariant/js/easyvariant.js', 1).'"></script>';
            self::$assets_loaded = true;
        }
        
        // Récupérer le produit
        $productId = GETPOST('id', 'int');
        if ($productId > 0) {
            require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
            $product = new Product($this->db);
            if ($product->fetch($productId) > 0) {
                
                // Charger les extrafields
                $product->fetch_optionals();
                
                // Récupérer les attributs autorisés
                $allowedAttributes = '';
                if (isset($product->array_options['options_vartemplate'])) {
                    $allowedAttributes = $product->array_options['options_vartemplate'];
                }
                
                // Configuration JavaScript UNE SEULE FOIS
                if (!self::$config_sent && !empty($allowedAttributes)) {
                    ?>
                    <script>
                    // Configuration EasyVariant
                    if (typeof window.easyVariantConfig === 'undefined') {
                        window.easyVariantConfig = {};
                    }
                    
                    Object.assign(window.easyVariantConfig, {
                        allowedAttributes: '<?php echo addslashes($allowedAttributes); ?>',
                        productId: <?php echo intval($product->id); ?>,
                        debug: false,
                        version: '1.0.5-FINAL'
                    });
                    
                    // Initialiser quand DOM prêt
                    $(document).ready(function() {
                        setTimeout(function() {
                            if (typeof initEasyVariantFiltering === 'function') {
                                initEasyVariantFiltering();
                            }
                        }, 300);
                    });
                    </script>
                    <?php
                    self::$config_sent = true;
                }
            }
        }
    }
}
?>