<?php
/**
 * Module descriptor class for EasyVariant
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 1.0.0
 */

dol_include_once('/core/modules/DolibarrModules.class.php');

class modEasyVariant extends DolibarrModules
{
    /**
     * Constructor
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Module identification
        $this->numero = 500200; // Unique module number
        $this->rights_class = 'easyvariant';
        
        // Module properties
        $this->family = "products";
        $this->module_position = 91;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "EasyVariant - Filtrage intelligent des attributs de variantes";
        $this->descriptionlong = "Module pour filtrer les attributs de variantes selon l'extrafield 'vartemplate' configuré sur chaque produit. Simplifie l'interface native de création de variantes en n'affichant que les attributs pertinents.";
        
        // Version info
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'product';
        
        // Module author
        $this->editor_name = 'Claude AI';
        $this->editor_url = '';
        
        // Dependencies
        $this->depends = array('modProduct');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("easyvariant@easyvariant");
        
        // Constants
        $this->const = array();
        
        // Module boxes
        $this->boxes = array();
        
        // Module permissions
        $this->rights = array();
        $r = 0;
        
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Utiliser EasyVariant';
        $this->rights[$r][4] = 'easyvariant';
        $this->rights[$r][5] = 'use';
        $r++;
        
        // Main menu entries
        $this->menu = array();
        
        // Module configuration page
        $this->config_page_url = array("admin.php@easyvariant");
        
        // Module parts
        $this->module_parts = array(
            'hooks' => array(
                'main'
            ),
            'css' => array(
                '/easyvariant/css/easyvariant.css'
            ),
            'js' => array(
                '/easyvariant/js/easyvariant.js'
            )
        );
        
        // Dictionaries
        $this->dictionaries = array();
        
        // Sql file list to execute on module activation
        $this->dirs = array("/easyvariant/temp");
    }

    /**
     * Function called when module is enabled
     */
    public function init($options = '')
    {
        global $conf, $langs, $user;
        
        // Initialize module configuration
        if (!isset($conf->global->EASYVARIANT_DEBUG)) {
            dolibarr_set_const($this->db, "EASYVARIANT_DEBUG", "0", 'chaine', 0, '', $conf->entity);
        }
        
        return $this->_init(array(), $options);
    }

    /**
     * Function called when module is disabled
     */
    public function remove($options = '')
    {
        $result = $this->_remove(array(), $options);
        return $result;
    }
}
?>