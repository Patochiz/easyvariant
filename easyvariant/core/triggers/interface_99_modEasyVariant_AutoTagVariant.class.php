<?php
/**
 * Trigger pour auto-tagging des variantes produit
 *
 * Intercepte PRODUCT_CREATE et PRODUCT_MODIFY pour :
 * - Si le produit est une variante → créer les tags plats et assigner
 * - Si le produit est un parent modifié (template changé) → re-tagger toutes ses variantes
 *
 * @package EasyVariant
 * @version 2.0.0
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceAutoTagVariant extends DolibarrTriggers
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->name        = preg_replace('/^Interface/i', '', get_class($this));
        $this->family      = "product";
        $this->description = "Auto-tagging plat des variantes produit selon le template EasyVariant";
        $this->version     = '2.0.0';
        $this->picto       = 'category';
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDesc()
    {
        return $this->description;
    }

    /**
     * @param string    $action     Code événement
     * @param Object    $object     Objet concerné
     * @param User      $user       Utilisateur
     * @param Translate $langs      Traductions
     * @param Conf      $conf       Configuration
     * @return int                  0=ok, <0=erreur
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        if (empty($conf->global->AUTOTAGVARIANT_ENABLED)) {
            return 0;
        }

        if ($action !== 'PRODUCT_CREATE' && $action !== 'PRODUCT_MODIFY') {
            return 0;
        }

        try {
            dol_include_once('/easyvariant/class/autotagvariant.class.php');
            $autoTag = new AutoTagVariant($this->db);

            $productId = $object->id;

            // CAS 1 : Le produit est une variante → tagger directement
            if ($this->isVariant($productId)) {
                dol_syslog("AutoTagVariant trigger: variant #$productId ($action)", LOG_INFO);
                $autoTag->processVariant($productId);
                return 0;
            }

            // CAS 2 : Le produit est un parent avec template → re-tagger toutes ses variantes
            if ($action === 'PRODUCT_MODIFY' && $this->isParentWithTemplate($productId)) {
                dol_syslog("AutoTagVariant trigger: parent #$productId modified, reprocessing variants", LOG_INFO);
                $autoTag->processAllVariantsOfParent($productId);
                return 0;
            }
        } catch (Exception $e) {
            dol_syslog("AutoTagVariant trigger ERROR: ".$e->getMessage(), LOG_ERR);
        }

        return 0;
    }

    private function isVariant($productId)
    {
        $sql  = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_attribute_combination";
        $sql .= " WHERE fk_product_child = ".intval($productId);

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return ($obj->nb > 0);
        }
        return false;
    }

    private function isParentWithTemplate($productId)
    {
        $sql  = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_extrafields";
        $sql .= " WHERE fk_object = ".intval($productId);
        $sql .= " AND vartemplate IS NOT NULL AND vartemplate != ''";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return ($obj->nb > 0);
        }
        return false;
    }
}
