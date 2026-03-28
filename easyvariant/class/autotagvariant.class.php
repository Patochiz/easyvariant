<?php
/**
 * AutoTagVariant - Auto-tagging plat des variantes produit
 *
 * Crée des tags (sous-catégories) pour chaque valeur d'attribut d'une variante,
 * directement sous la catégorie du produit parent (tags plats, pas hiérarchiques).
 *
 * Exemple:
 *   Produit parent MO dans catégorie Plafond>>Molene
 *   Variante MO_200_1EXT_0.70_PRE_RAL_9010_NP
 *   → Tags créés : Plafond>>Molene>>200, Plafond>>Molene>>1EXT,
 *     Plafond>>Molene>>0.70, Plafond>>Molene>>PRE,
 *     Plafond>>Molene>>RAL_9010, Plafond>>Molene>>NP
 *   → Variante assignée à TOUS ces tags
 *
 * @package EasyVariant
 * @version 2.0.0
 */

require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

class AutoTagVariant
{
    /** @var DoliDB */
    private $db;

    /** @var bool */
    private $debug;

    /** @var string[] Log messages */
    public $logs = array();

    /** @var int Compteur catégories créées */
    public $created_categories = 0;

    /** @var int Compteur produits assignés */
    public $assigned_products = 0;

    /** @var string[] Erreurs */
    public $errors = array();

    /**
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
        global $conf;
        $this->debug = !empty($conf->global->AUTOTAGVARIANT_DEBUG);
    }

    private function log($msg)
    {
        $this->logs[] = $msg;
        if ($this->debug) {
            dol_syslog("AutoTagVariant: ".$msg, LOG_DEBUG);
        }
    }

    // =========================================================================
    // PUBLIC ENTRY POINTS
    // =========================================================================

    /**
     * Traite une seule variante : lit ses attributs, crée les tags plats, assigne.
     *
     * @param  int  $variantProductId   rowid du produit variante
     * @param  bool $dryRun             true = simulation sans écriture
     * @return int                      1=ok, 0=ignoré, -1=erreur
     */
    public function processVariant($variantProductId, $dryRun = false)
    {
        $combination = $this->getCombinationByProductId($variantProductId);
        if (!$combination) {
            $this->log("Produit #$variantProductId : pas une variante, ignoré");
            return 0;
        }

        $parentProductId = $combination['fk_product_parent'];
        $combinationId   = $combination['rowid'];

        $templateOrder = $this->getTemplateOrder($parentProductId);

        $attrValues = $this->getCombinationValues($combinationId);
        if (empty($attrValues)) {
            $this->log("Variante #$variantProductId (combination #$combinationId) : aucune valeur d'attribut");
            return 0;
        }

        $orderedValues = $this->orderValuesByTemplate($attrValues, $templateOrder);

        $parentCategories = $this->getProductCategories($parentProductId);
        if (empty($parentCategories)) {
            $this->log("Produit parent #$parentProductId : aucune catégorie, impossible de créer les tags");
            return 0;
        }

        $product = new Product($this->db);
        if ($product->fetch($variantProductId) <= 0) {
            $this->errors[] = "Impossible de charger le produit variante #$variantProductId";
            return -1;
        }

        foreach ($parentCategories as $parentCatId) {
            // Retirer la variante des anciennes sous-catégories (gestion changement template)
            if (!$dryRun) {
                $this->removeFromSubcategories($variantProductId, $parentCatId);
            }

            // Créer/trouver les tags plats et assigner
            $tagCatIds = $this->ensureFlatTags($parentCatId, $orderedValues, $dryRun);

            if (!$dryRun) {
                foreach ($tagCatIds as $tagCatId) {
                    if ($tagCatId > 0 && !$this->isProductInCategory($variantProductId, $tagCatId)) {
                        $cat = new Categorie($this->db);
                        if ($cat->fetch($tagCatId) > 0) {
                            $result = $cat->add_type($product, Categorie::TYPE_PRODUCT);
                            if ($result >= 0) {
                                $this->assigned_products++;
                                $this->log("Produit #$variantProductId ({$product->ref}) → tag #{$tagCatId} ({$cat->label})");
                            } else {
                                $this->errors[] = "Erreur assignation produit #$variantProductId → tag #$tagCatId";
                            }
                        }
                    }
                }
            } else {
                $path = $this->buildCategoryPathPreview($parentCatId);
                foreach ($orderedValues as $val) {
                    $this->log("[SIMULATION] {$product->ref} → $path>>$val");
                }
            }
        }

        return 1;
    }

    /**
     * Traite TOUTES les variantes d'un produit parent donné.
     *
     * @param  int  $parentProductId
     * @param  bool $dryRun
     * @return int  Nombre de variantes traitées
     */
    public function processAllVariantsOfParent($parentProductId, $dryRun = false)
    {
        $variants = $this->getVariantProductIds($parentProductId);
        $count = 0;

        $parent = new Product($this->db);
        $parent->fetch($parentProductId);
        $this->log("=== Produit parent #{$parentProductId} ({$parent->ref}) : ".count($variants)." variante(s) ===");

        foreach ($variants as $variantId) {
            $result = $this->processVariant($variantId, $dryRun);
            if ($result > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Traite TOUTES les variantes de la base dont le parent a un vartemplate configuré.
     *
     * @param  bool $dryRun
     * @return int  Nombre total de variantes traitées
     */
    public function processAllVariants($dryRun = false)
    {
        $sql  = "SELECT DISTINCT pac.fk_product_parent";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = pac.fk_product_parent";
        $sql .= " WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = "Erreur SQL : ".$this->db->lasterror();
            return -1;
        }

        $count = 0;
        while ($obj = $this->db->fetch_object($resql)) {
            $count += $this->processAllVariantsOfParent($obj->fk_product_parent, $dryRun);
        }

        return $count;
    }

    /**
     * Nettoie les catégories vides créées par le module.
     * Ne supprime que les catégories produit sans produit ET sans sous-catégorie.
     * Travaille de la feuille vers la racine (bottom-up).
     *
     * @param  int  $rootCatId  Optionnel: limiter au sous-arbre d'une catégorie
     * @param  bool $dryRun
     * @return int  Nombre de catégories supprimées
     */
    public function cleanEmptyCategories($rootCatId = 0, $dryRun = false)
    {
        global $user;
        $deleted = 0;
        $maxIterations = 50;

        for ($i = 0; $i < $maxIterations; $i++) {
            $sql  = "SELECT c.rowid, c.label, c.fk_parent";
            $sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
            $sql .= " WHERE c.type = ".Categorie::TYPE_PRODUCT;
            $sql .= " AND c.rowid NOT IN (SELECT fk_parent FROM ".MAIN_DB_PREFIX."categorie WHERE fk_parent > 0)";
            $sql .= " AND c.rowid NOT IN (SELECT fk_categorie FROM ".MAIN_DB_PREFIX."categorie_product)";

            if ($rootCatId > 0) {
                $descendants = $this->getAllDescendantCategoryIds($rootCatId);
                if (!empty($descendants)) {
                    $sql .= " AND c.rowid IN (".implode(',', array_map('intval', $descendants)).")";
                } else {
                    break;
                }
            }

            $resql = $this->db->query($sql);
            if (!$resql || $this->db->num_rows($resql) == 0) {
                break;
            }

            $deletedThisRound = 0;
            while ($obj = $this->db->fetch_object($resql)) {
                if ($dryRun) {
                    $this->log("[SIMULATION] Suppression catégorie #{$obj->rowid} ({$obj->label})");
                    $deletedThisRound++;
                } else {
                    $cat = new Categorie($this->db);
                    if ($cat->fetch($obj->rowid) > 0) {
                        $result = $cat->delete($user);
                        if ($result > 0) {
                            $this->log("Catégorie #{$obj->rowid} ({$obj->label}) supprimée");
                            $deletedThisRound++;
                        }
                    }
                }
            }

            $deleted += $deletedThisRound;
            if ($deletedThisRound == 0) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Retourne des statistiques pour la page admin.
     *
     * @return array
     */
    public function getStats()
    {
        $stats = array();

        $sql = "SELECT COUNT(DISTINCT pac.fk_product_parent) as nb";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = pac.fk_product_parent";
        $sql .= " WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";
        $resql = $this->db->query($sql);
        $stats['parents_with_template'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_attribute_combination";
        $resql = $this->db->query($sql);
        $stats['total_variants'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        $sql = "SELECT COUNT(*) as nb";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = pac.fk_product_parent";
        $sql .= " WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";
        $resql = $this->db->query($sql);
        $stats['variants_with_template'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        return $stats;
    }

    // =========================================================================
    // PRIVATE: DATABASE QUERIES
    // =========================================================================

    private function getCombinationByProductId($productId)
    {
        $sql  = "SELECT rowid, fk_product_parent, fk_product_child";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination";
        $sql .= " WHERE fk_product_child = ".intval($productId);
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return array(
                'rowid'              => $obj->rowid,
                'fk_product_parent'  => $obj->fk_product_parent,
                'fk_product_child'   => $obj->fk_product_child,
            );
        }
        return null;
    }

    private function getTemplateOrder($parentProductId)
    {
        $sql  = "SELECT vartemplate FROM ".MAIN_DB_PREFIX."product_extrafields";
        $sql .= " WHERE fk_object = ".intval($parentProductId);

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            if (!empty($obj->vartemplate)) {
                $ids = array_map('intval', explode(',', $obj->vartemplate));
                $ids = array_filter($ids, function ($v) { return $v > 0; });
                $this->log("Template parent #$parentProductId : [".implode(', ', $ids)."]");
                return array_values($ids);
            }
        }

        $this->log("Pas de template pour parent #$parentProductId, tri par position");
        return array();
    }

    private function getCombinationValues($combinationId)
    {
        $sql  = "SELECT c2v.fk_prod_attr as attr_id, c2v.fk_prod_attr_val as value_id,";
        $sql .= " pa.label as attr_label, pa.position as attr_position,";
        $sql .= " pav.ref as value_ref, pav.value as value_label";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination2val as c2v";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute as pa ON pa.rowid = c2v.fk_prod_attr";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as pav ON pav.rowid = c2v.fk_prod_attr_val";
        $sql .= " WHERE c2v.fk_prod_combination = ".intval($combinationId);

        $resql = $this->db->query($sql);
        if (!$resql) {
            return array();
        }

        $values = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $values[$obj->attr_id] = array(
                'attr_id'       => $obj->attr_id,
                'attr_label'    => $obj->attr_label,
                'attr_position' => $obj->attr_position,
                'value_ref'     => $obj->value_ref,
                'value_label'   => $obj->value_label,
            );
        }

        return $values;
    }

    private function orderValuesByTemplate($attrValues, $templateOrder)
    {
        $ordered = array();

        if (!empty($templateOrder)) {
            foreach ($templateOrder as $attrId) {
                if (isset($attrValues[$attrId])) {
                    $label = !empty($attrValues[$attrId]['value_ref'])
                        ? $attrValues[$attrId]['value_ref']
                        : $attrValues[$attrId]['value_label'];
                    $ordered[] = $label;
                }
            }
        } else {
            uasort($attrValues, function ($a, $b) {
                return $a['attr_position'] - $b['attr_position'];
            });
            foreach ($attrValues as $val) {
                $label = !empty($val['value_ref']) ? $val['value_ref'] : $val['value_label'];
                $ordered[] = $label;
            }
        }

        $this->log("Valeurs ordonnées : [".implode(' / ', $ordered)."]");
        return $ordered;
    }

    private function getProductCategories($productId)
    {
        $sql  = "SELECT fk_categorie FROM ".MAIN_DB_PREFIX."categorie_product";
        $sql .= " WHERE fk_product = ".intval($productId);

        $resql = $this->db->query($sql);
        if (!$resql) {
            return array();
        }

        $catIds = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $catIds[] = (int)$obj->fk_categorie;
        }
        return $catIds;
    }

    private function getVariantProductIds($parentProductId)
    {
        $sql  = "SELECT fk_product_child FROM ".MAIN_DB_PREFIX."product_attribute_combination";
        $sql .= " WHERE fk_product_parent = ".intval($parentProductId);

        $resql = $this->db->query($sql);
        if (!$resql) {
            return array();
        }

        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int)$obj->fk_product_child;
        }
        return $ids;
    }

    // =========================================================================
    // PRIVATE: FLAT TAG MANAGEMENT
    // =========================================================================

    /**
     * Crée/trouve les tags plats (sous-catégories directes) sous une catégorie parente.
     *
     * @param  int      $parentCatId   ID de la catégorie du produit parent (ex: Molene)
     * @param  string[] $valueLabels   Valeurs d'attributs (ex: ['200', '1EXT', '0.70', 'PRE', 'RAL_9010', 'NP'])
     * @param  bool     $dryRun
     * @return int[]    IDs des catégories tags créées/trouvées
     */
    private function ensureFlatTags($parentCatId, $valueLabels, $dryRun = false)
    {
        $tagIds = array();

        foreach ($valueLabels as $label) {
            $label = trim($label);
            if (empty($label)) {
                continue;
            }

            $existingId = $this->findChildCategory($parentCatId, $label);

            if ($existingId > 0) {
                $tagIds[] = $existingId;
            } else {
                if ($dryRun) {
                    $this->log("[SIMULATION] Créerait tag '$label' sous catégorie #$parentCatId");
                    $tagIds[] = 999999;
                } else {
                    $newCatId = $this->createCategory($label, $parentCatId);
                    if ($newCatId > 0) {
                        $this->created_categories++;
                        $this->log("Tag créé : '$label' (#{$newCatId}) sous catégorie #{$parentCatId}");
                        $tagIds[] = $newCatId;
                    } else {
                        $this->errors[] = "Erreur création tag '$label' sous catégorie #$parentCatId";
                    }
                }
            }
        }

        return $tagIds;
    }

    private function findChildCategory($parentCatId, $label)
    {
        $sql  = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie";
        $sql .= " WHERE fk_parent = ".intval($parentCatId);
        $sql .= " AND type = ".Categorie::TYPE_PRODUCT;
        $sql .= " AND label = '".$this->db->escape($label)."'";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return (int)$obj->rowid;
        }
        return 0;
    }

    private function createCategory($label, $parentCatId)
    {
        global $user;

        $cat = new Categorie($this->db);
        $cat->label     = $label;
        $cat->type      = Categorie::TYPE_PRODUCT;
        $cat->fk_parent = $parentCatId;

        $result = $cat->create($user);
        if ($result > 0) {
            return $result;
        }

        $this->log("Erreur création catégorie '$label': ".implode(', ', $cat->errors));
        return -1;
    }

    private function isProductInCategory($productId, $catId)
    {
        $sql  = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."categorie_product";
        $sql .= " WHERE fk_product = ".intval($productId);
        $sql .= " AND fk_categorie = ".intval($catId);

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return ($obj->nb > 0);
        }
        return false;
    }

    /**
     * Retire un produit de toutes les sous-catégories directes d'une catégorie donnée.
     */
    private function removeFromSubcategories($productId, $rootCatId)
    {
        $sql  = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie";
        $sql .= " WHERE fk_parent = ".intval($rootCatId);
        $sql .= " AND type = ".Categorie::TYPE_PRODUCT;

        $resql = $this->db->query($sql);
        if (!$resql) {
            return;
        }

        $childCatIds = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $childCatIds[] = (int)$obj->rowid;
        }

        if (empty($childCatIds)) {
            return;
        }

        $sql  = "DELETE FROM ".MAIN_DB_PREFIX."categorie_product";
        $sql .= " WHERE fk_product = ".intval($productId);
        $sql .= " AND fk_categorie IN (".implode(',', $childCatIds).")";

        $this->db->query($sql);
    }

    private function getAllDescendantCategoryIds($catId)
    {
        $descendants = array();
        $toProcess = array($catId);
        $maxDepth = 20;
        $depth = 0;

        while (!empty($toProcess) && $depth < $maxDepth) {
            $sql  = "SELECT rowid FROM ".MAIN_DB_PREFIX."categorie";
            $sql .= " WHERE fk_parent IN (".implode(',', array_map('intval', $toProcess)).")";
            $sql .= " AND type = ".Categorie::TYPE_PRODUCT;

            $resql = $this->db->query($sql);
            $toProcess = array();

            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $descendants[] = (int)$obj->rowid;
                    $toProcess[]   = (int)$obj->rowid;
                }
            }
            $depth++;
        }

        return $descendants;
    }

    /**
     * Construit un aperçu du chemin complet d'une catégorie pour la simulation.
     */
    private function buildCategoryPathPreview($catId)
    {
        $cat = new Categorie($this->db);
        $cat->fetch($catId);

        $path = $cat->label;

        $tmpCat = $cat;
        while ($tmpCat->fk_parent > 0) {
            $parentCat = new Categorie($this->db);
            $parentCat->fetch($tmpCat->fk_parent);
            $path = $parentCat->label.'>>'.$path;
            $tmpCat = $parentCat;
        }

        return $path;
    }
}
