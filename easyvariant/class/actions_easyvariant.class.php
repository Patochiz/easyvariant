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
        if ($this->shouldProcess()) {
            $this->processVariantPage();
        }

        // Auto-tagging des variantes (s'exécute après toutes les opérations DB)
        $this->processAutoTagging();

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

    /**
     * Auto-tagging des variantes sur la page combinations.php
     * S'exécute dans printCommonFooter, après toutes les opérations DB
     */
    private function processAutoTagging()
    {
        global $conf;

        dol_syslog("EasyVariant::processAutoTagging() called - URI: ".$_SERVER['REQUEST_URI'], LOG_INFO);

        if (empty($conf->global->AUTOTAGVARIANT_ENABLED)) {
            dol_syslog("EasyVariant::processAutoTagging() AUTOTAGVARIANT_ENABLED is empty/disabled", LOG_WARNING);
            return;
        }

        $uri = $_SERVER['REQUEST_URI'];
        if (strpos($uri, '/variants/combinations.php') === false) {
            return;
        }

        $productId = GETPOST('id', 'int');
        if ($productId <= 0) {
            dol_syslog("EasyVariant::processAutoTagging() no product ID in URL", LOG_WARNING);
            return;
        }

        dol_syslog("EasyVariant::processAutoTagging() processing parent product #$productId", LOG_INFO);

        try {
            dol_include_once('/easyvariant/class/autotagvariant.class.php');
            $autoTag = new AutoTagVariant($this->db);
            $result = $autoTag->processUntaggedVariantsOfParent($productId);
            dol_syslog("EasyVariant::processAutoTagging() result: $result variant(s), "
                .$autoTag->created_categories." cat created, "
                .$autoTag->assigned_products." assigned, "
                .count($autoTag->errors)." errors", LOG_INFO);
            if (!empty($autoTag->errors)) {
                foreach ($autoTag->errors as $err) {
                    dol_syslog("EasyVariant::processAutoTagging() ERROR: ".$err, LOG_ERR);
                }
            }
            if (!empty($autoTag->logs)) {
                foreach ($autoTag->logs as $log) {
                    dol_syslog("EasyVariant::processAutoTagging() LOG: ".$log, LOG_DEBUG);
                }
            }
        } catch (Exception $e) {
            dol_syslog("EasyVariant::processAutoTagging() EXCEPTION: ".$e->getMessage(), LOG_ERR);
        }
    }
}
?>