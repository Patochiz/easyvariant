Dans le repo GitHub Patochiz/easyvariant, crée une branche feature/autotag-variant et pousse les modifications suivantes.

## Étapes git

1. Clone le repo https://github.com/Patochiz/easyvariant.git (ou utilise-le s'il est déjà cloné)
2. git checkout -b feature/autotag-variant depuis main
3. Crée/modifie les 6 fichiers listés ci-dessous (contenu COMPLET fourni, REMPLACE entièrement chaque fichier)
4. git add -A
5. git commit avec le message fourni à la fin
6. git push -u origin feature/autotag-variant

## FICHIER 1 (NOUVEAU) : easyvariant/class/autotagvariant.class.php

```php
<?php
/**
 * AutoTagVariant - Logique de catégorisation automatique des variantes
 *
 * Crée une arborescence de catégories basée sur les attributs de variantes
 * en utilisant le template EasyVariant (extrafield vartemplate) pour l'ordre.
 *
 * Exemple:
 *   Produit parent MO → catégorie Plafond/MOLENE
 *   Variante MO_300_2INT_0.80_PRE_RAL_1015_23%2.5
 *   Template: [3.Largeur, 7.type, 8.épaisseur, 9.laquage, 10.couleur, 11.perforation]
 *   → Catégorie créée: Plafond/MOLENE/300/2INT/0.80/PRE/RAL_1015/23%2.5
 *   → Variante assignée à la catégorie feuille 23%2.5 uniquement
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

    /** @var string[] Log messages for batch/admin display */
    public $logs = array();

    /** @var int Compteur catégories créées */
    public $created_categories = 0;

    /** @var int Compteur produits assignés */
    public $assigned_products = 0;

    /** @var string[] Erreurs rencontrées */
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
     * Traite une seule variante : lit ses attributs, construit le chemin de catégorie, assigne.
     *
     * @param  int  $variantProductId   rowid du produit variante
     * @param  bool $dryRun             true = simulation sans écriture
     * @return int                      1=ok, 0=ignoré, -1=erreur
     */
    public function processVariant($variantProductId, $dryRun = false)
    {
        // 1. Récupérer l'info combination pour cette variante
        $combination = $this->getCombinationByProductId($variantProductId);
        if (!$combination) {
            $this->log("Produit #$variantProductId : pas une variante, ignoré");
            return 0;
        }

        $parentProductId = $combination['fk_product_parent'];
        $combinationId   = $combination['rowid'];

        // 2. Récupérer l'ordre du template depuis l'extrafield vartemplate du parent
        $templateOrder = $this->getTemplateOrder($parentProductId);

        // 3. Récupérer les valeurs d'attributs de la combinaison
        $attrValues = $this->getCombinationValues($combinationId);
        if (empty($attrValues)) {
            $this->log("Variante #$variantProductId (combination #$combinationId) : aucune valeur d'attribut");
            return 0;
        }

        // 4. Ordonner selon le template (ou par position si pas de template)
        $orderedValues = $this->orderValuesByTemplate($attrValues, $templateOrder);

        // 5. Récupérer les catégories du produit parent
        $parentCategories = $this->getProductCategories($parentProductId);
        if (empty($parentCategories)) {
            $this->log("Produit parent #$parentProductId : aucune catégorie, impossible de créer l'arborescence");
            return 0;
        }

        // 6. Charger le produit variante
        $product = new Product($this->db);
        if ($product->fetch($variantProductId) <= 0) {
            $this->errors[] = "Impossible de charger le produit variante #$variantProductId";
            return -1;
        }

        // 7. Pour chaque catégorie du parent, construire le sous-arbre et assigner
        foreach ($parentCategories as $parentCatId) {
            $leafCatId = $this->ensureCategoryPath($parentCatId, $orderedValues, $dryRun);

            if ($leafCatId > 0 && !$dryRun) {
                // Retirer la variante des anciennes sous-catégories (gestion changement template)
                $this->removeFromSubcategories($variantProductId, $parentCatId);

                // Assigner à la catégorie feuille uniquement
                $cat = new Categorie($this->db);
                if ($cat->fetch($leafCatId) > 0) {
                    if (!$this->isProductInCategory($variantProductId, $leafCatId)) {
                        $result = $cat->add_type($product, Categorie::TYPE_PRODUCT);
                        if ($result >= 0) {
                            $this->assigned_products++;
                            $this->log("Produit #$variantProductId ({$product->ref}) → catégorie #{$leafCatId} ({$cat->label})");
                        } else {
                            $this->errors[] = "Erreur assignation produit #$variantProductId → catégorie #$leafCatId";
                        }
                    } else {
                        $this->log("Produit #$variantProductId déjà dans catégorie #$leafCatId");
                    }
                }
            } elseif ($dryRun) {
                $path = $this->buildCategoryPathPreview($parentCatId, $orderedValues);
                $this->log("[SIMULATION] {$product->ref} → $path");
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
        $deleted = 0;
        $maxIterations = 50; // sécurité anti-boucle infinie

        for ($i = 0; $i < $maxIterations; $i++) {
            // Trouver les catégories produit feuilles (sans sous-catégorie) et vides (sans produit)
            $sql  = "SELECT c.rowid, c.label, c.fk_parent";
            $sql .= " FROM ".MAIN_DB_PREFIX."categorie as c";
            $sql .= " WHERE c.type = ".Categorie::TYPE_PRODUCT;
            // Pas de sous-catégorie
            $sql .= " AND c.rowid NOT IN (SELECT fk_parent FROM ".MAIN_DB_PREFIX."categorie WHERE fk_parent > 0)";
            // Pas de produit
            $sql .= " AND c.rowid NOT IN (SELECT fk_categorie FROM ".MAIN_DB_PREFIX."categorie_product)";

            if ($rootCatId > 0) {
                // Limiter au sous-arbre
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
                        $result = $cat->delete($user = null);
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

    // =========================================================================
    // PRIVATE: DATABASE QUERIES
    // =========================================================================

    /**
     * Récupère l'info combination pour un produit variante.
     *
     * @param  int        $productId
     * @return array|null array('rowid'=>..., 'fk_product_parent'=>...) ou null
     */
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

    /**
     * Récupère l'ordre des attributs depuis l'extrafield vartemplate du parent.
     * vartemplate contient une liste d'IDs d'attributs séparés par virgule, ex: "3,7,8,9,10,11"
     *
     * @param  int   $parentProductId
     * @return int[] Array d'IDs d'attributs ordonnés, vide si pas de template
     */
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

    /**
     * Récupère les valeurs d'attributs d'une combinaison.
     *
     * @param  int   $combinationId   rowid de llx_product_attribute_combination
     * @return array keyed by attr_id => array('attr_id'=>, 'attr_label'=>, 'attr_position'=>, 'value_ref'=>, 'value_label'=>)
     */
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

    /**
     * Ordonne les valeurs d'attributs selon le template, ou par position si pas de template.
     *
     * @param  array $attrValues    keyed by attr_id
     * @param  int[] $templateOrder IDs ordonnés
     * @return array ordered array of value labels (for category names)
     */
    private function orderValuesByTemplate($attrValues, $templateOrder)
    {
        $ordered = array();

        if (!empty($templateOrder)) {
            // Suivre l'ordre du template
            foreach ($templateOrder as $attrId) {
                if (isset($attrValues[$attrId])) {
                    // Utiliser value_ref en priorité, sinon value_label
                    $label = !empty($attrValues[$attrId]['value_ref'])
                        ? $attrValues[$attrId]['value_ref']
                        : $attrValues[$attrId]['value_label'];
                    $ordered[] = $label;
                }
            }
        } else {
            // Trier par position de l'attribut
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

    /**
     * Récupère les IDs de catégories produit d'un produit.
     *
     * @param  int   $productId
     * @return int[]
     */
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

    /**
     * Récupère tous les IDs de produits variantes d'un parent.
     *
     * @param  int   $parentProductId
     * @return int[]
     */
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
    // PRIVATE: CATEGORY TREE MANAGEMENT
    // =========================================================================

    /**
     * S'assure que le chemin de catégories existe sous une catégorie parente.
     * Crée les catégories manquantes.
     *
     * @param  int      $parentCatId     ID de la catégorie racine (du parent produit)
     * @param  string[] $pathParts       Ex: ['300', '2INT', '0.80', 'PRE', 'RAL_1015', '23%2.5']
     * @param  bool     $dryRun
     * @return int      ID de la catégorie feuille créée/trouvée, ou -1 en erreur
     */
    private function ensureCategoryPath($parentCatId, $pathParts, $dryRun = false)
    {
        $currentParentId = $parentCatId;

        foreach ($pathParts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Chercher si la sous-catégorie existe déjà
            $existingId = $this->findChildCategory($currentParentId, $part);

            if ($existingId > 0) {
                $currentParentId = $existingId;
            } else {
                if ($dryRun) {
                    $this->log("[SIMULATION] Créerait catégorie '$part' sous #$currentParentId");
                    // En dry run on ne peut pas continuer l'arbre, on retourne un ID fictif
                    return 999999;
                }

                // Créer la sous-catégorie
                $newCatId = $this->createCategory($part, $currentParentId);
                if ($newCatId > 0) {
                    $this->created_categories++;
                    $this->log("Catégorie créée : '$part' (#{$newCatId}) sous #{$currentParentId}");
                    $currentParentId = $newCatId;
                } else {
                    $this->errors[] = "Erreur création catégorie '$part' sous #$currentParentId";
                    return -1;
                }
            }
        }

        return $currentParentId;
    }

    /**
     * Cherche une sous-catégorie par label sous un parent donné.
     *
     * @param  int    $parentCatId
     * @param  string $label
     * @return int    rowid ou 0 si non trouvé
     */
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

    /**
     * Crée une catégorie produit.
     *
     * @param  string $label
     * @param  int    $parentCatId
     * @return int    rowid de la catégorie créée, ou -1 en erreur
     */
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

    /**
     * Vérifie si un produit est déjà dans une catégorie.
     *
     * @param  int  $productId
     * @param  int  $catId
     * @return bool
     */
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
     * Retire un produit de toutes les sous-catégories d'une catégorie donnée.
     * (pour gérer le changement de template)
     *
     * @param int $productId
     * @param int $rootCatId
     */
    private function removeFromSubcategories($productId, $rootCatId)
    {
        $descendants = $this->getAllDescendantCategoryIds($rootCatId);
        if (empty($descendants)) {
            return;
        }

        $sql  = "DELETE FROM ".MAIN_DB_PREFIX."categorie_product";
        $sql .= " WHERE fk_product = ".intval($productId);
        $sql .= " AND fk_categorie IN (".implode(',', array_map('intval', $descendants)).")";

        $this->db->query($sql);
    }

    /**
     * Récupère tous les IDs de catégories descendantes (récursif).
     *
     * @param  int   $catId
     * @return int[]
     */
    private function getAllDescendantCategoryIds($catId)
    {
        $descendants = array();
        $toProcess = array($catId);

        $maxDepth = 20; // sécurité
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
     * Construit un aperçu du chemin de catégorie pour le mode simulation.
     *
     * @param  int      $parentCatId
     * @param  string[] $pathParts
     * @return string
     */
    private function buildCategoryPathPreview($parentCatId, $pathParts)
    {
        // Récupérer le chemin complet de la catégorie parente
        $cat = new Categorie($this->db);
        $cat->fetch($parentCatId);

        $path = $cat->label;

        // Remonter les parents
        $tmpCat = $cat;
        while ($tmpCat->fk_parent > 0) {
            $parentCat = new Categorie($this->db);
            $parentCat->fetch($tmpCat->fk_parent);
            $path = $parentCat->label.'/'.$path;
            $tmpCat = $parentCat;
        }

        return $path.'/'.implode('/', $pathParts);
    }

    /**
     * Retourne des statistiques pour la page admin.
     *
     * @return array
     */
    public function getStats()
    {
        $stats = array();

        // Nombre de produits parents avec template
        $sql = "SELECT COUNT(DISTINCT pac.fk_product_parent) as nb";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = pac.fk_product_parent";
        $sql .= " WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";
        $resql = $this->db->query($sql);
        $stats['parents_with_template'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        // Nombre total de variantes
        $sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_attribute_combination";
        $resql = $this->db->query($sql);
        $stats['total_variants'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        // Nombre de variantes dont le parent a un template
        $sql = "SELECT COUNT(*) as nb";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as pac";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_extrafields as pe ON pe.fk_object = pac.fk_product_parent";
        $sql .= " WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";
        $resql = $this->db->query($sql);
        $stats['variants_with_template'] = ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj->nb : 0;

        return $stats;
    }
}
```

## FICHIER 2 (NOUVEAU) : easyvariant/core/triggers/interface_99_modEasyVariant_AutoTagVariant.class.php

```php
<?php
/**
 * Trigger pour catégorisation automatique des variantes
 *
 * Intercepte PRODUCT_CREATE et PRODUCT_MODIFY pour :
 * - Si le produit est une variante → catégoriser cette variante
 * - Si le produit est un parent (et que son template est modifié) → recatégoriser toutes ses variantes
 *
 * @package EasyVariant
 * @version 2.0.0
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceAutoTagVariant extends DolibarrTriggers
{
    /**
     * @var DoliDB
     */
    private $db;

    /**
     * Constructor
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->name        = preg_replace('/^Interface/i', '', get_class($this));
        $this->family      = "product";
        $this->description = "Auto-catégorisation des variantes produit selon le template EasyVariant";
        $this->version     = '2.0.0';
        $this->picto       = 'category';
    }

    /**
     * Nom du trigger
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Description du trigger
     */
    public function getDesc()
    {
        return $this->description;
    }

    /**
     * Exécution du trigger
     *
     * @param string    $action     Code événement (PRODUCT_CREATE, PRODUCT_MODIFY, etc.)
     * @param Object    $object     Objet concerné
     * @param User      $user       Utilisateur
     * @param Translate $langs      Traductions
     * @param Conf      $conf       Configuration
     * @return int                  0=ok, <0=erreur
     */
    public function runTrigger($action, $object, $user, $langs, $conf)
    {
        // Vérifier que le module auto-tag est activé
        if (empty($conf->global->AUTOTAGVARIANT_ENABLED)) {
            return 0;
        }

        // On ne traite que les événements produit
        if ($action !== 'PRODUCT_CREATE' && $action !== 'PRODUCT_MODIFY') {
            return 0;
        }

        // Charger la classe de traitement
        dol_include_once('/easyvariant/class/autotagvariant.class.php');
        $autoTag = new AutoTagVariant($this->db);

        $productId = $object->id;

        // CAS 1 : Le produit est une variante → catégoriser directement
        if ($this->isVariant($productId)) {
            dol_syslog("AutoTagVariant trigger: PRODUCT variant #$productId ($action)", LOG_INFO);
            $autoTag->processVariant($productId);
            return 0;
        }

        // CAS 2 : Le produit est un parent avec template → recatégoriser toutes ses variantes
        // (utile quand on modifie le template ou les catégories du parent)
        if ($action === 'PRODUCT_MODIFY' && $this->isParentWithTemplate($productId)) {
            dol_syslog("AutoTagVariant trigger: PARENT #$productId modified, reprocessing all variants", LOG_INFO);
            $autoTag->processAllVariantsOfParent($productId);
            return 0;
        }

        return 0;
    }

    /**
     * Vérifie si un produit est une variante (a une entrée dans product_attribute_combination)
     *
     * @param  int  $productId
     * @return bool
     */
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

    /**
     * Vérifie si un produit est un parent avec un vartemplate configuré
     *
     * @param  int  $productId
     * @return bool
     */
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
```

## FICHIER 3 (MODIFIER) : easyvariant/core/modules/modEasyVariant.class.php

Remplacer entièrement le contenu par :

```php
<?php
/**
 * Module descriptor class for EasyVariant
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 2.0.0
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
        $this->description = "EasyVariant - Filtrage et catégorisation automatique des variantes";
        $this->descriptionlong = "Module pour filtrer les attributs de variantes selon l'extrafield 'vartemplate' configuré sur chaque produit, "
            ."et catégoriser automatiquement les variantes dans une arborescence de catégories basée sur leurs attributs. "
            ."Exemple: Plafond/MOLENE/300/2INT/0.80/PRE/RAL_1015/23%2.5";
        
        // Version info
        $this->version = '2.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'product';
        
        // Module author
        $this->editor_name = 'DIAMANT INDUSTRIE';
        $this->editor_url = '';
        
        // Dependencies
        $this->depends = array('modProduct', 'modCategorie');
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
        
        // Module parts - hooks + triggers + assets
        $this->module_parts = array(
            'triggers' => 1,
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
        
        // EasyVariant - filtrage attributs
        if (!isset($conf->global->EASYVARIANT_DEBUG)) {
            dolibarr_set_const($this->db, "EASYVARIANT_DEBUG", "0", 'chaine', 0, '', $conf->entity);
        }

        // AutoTagVariant - catégorisation automatique
        if (!isset($conf->global->AUTOTAGVARIANT_ENABLED)) {
            dolibarr_set_const($this->db, "AUTOTAGVARIANT_ENABLED", "1", 'chaine', 0, '', $conf->entity);
        }
        if (!isset($conf->global->AUTOTAGVARIANT_DEBUG)) {
            dolibarr_set_const($this->db, "AUTOTAGVARIANT_DEBUG", "0", 'chaine', 0, '', $conf->entity);
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
?>```

## FICHIER 4 (MODIFIER) : easyvariant/admin/admin.php

Remplacer entièrement le contenu par :

```php
<?php
/**
 * EasyVariant Administration Page
 * Includes: attribute filtering config + AutoTag batch processing
 *
 * @package EasyVariant
 * @version 2.0.0
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/easyvariant/class/autotagvariant.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->load("admin");
$langs->load("easyvariant@easyvariant");

$action = GETPOST('action', 'alpha');
$parentId = GETPOST('parent_id', 'int');

// =========================================================================
// ACTIONS
// =========================================================================

// Sauvegarder la configuration
if ($action == 'save_config') {
    $debug = GETPOST('EASYVARIANT_DEBUG', 'alpha') ? '1' : '0';
    $autotagEnabled = GETPOST('AUTOTAGVARIANT_ENABLED', 'alpha') ? '1' : '0';
    $autotagDebug = GETPOST('AUTOTAGVARIANT_DEBUG', 'alpha') ? '1' : '0';

    dolibarr_set_const($db, "EASYVARIANT_DEBUG", $debug, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, "AUTOTAGVARIANT_ENABLED", $autotagEnabled, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, "AUTOTAGVARIANT_DEBUG", $autotagDebug, 'chaine', 0, '', $conf->entity);

    setEventMessage("Configuration sauvegardée", 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Simulation globale
$simulationLogs = array();
if ($action == 'simulate_all') {
    $autoTag = new AutoTagVariant($db);
    $count = $autoTag->processAllVariants(true);
    $simulationLogs = $autoTag->logs;
    if (!empty($autoTag->errors)) {
        foreach ($autoTag->errors as $err) {
            setEventMessage($err, 'errors');
        }
    }
}

// Simulation d'un parent
if ($action == 'simulate_parent' && $parentId > 0) {
    $autoTag = new AutoTagVariant($db);
    $count = $autoTag->processAllVariantsOfParent($parentId, true);
    $simulationLogs = $autoTag->logs;
}

// Exécution globale
if ($action == 'execute_all') {
    $autoTag = new AutoTagVariant($db);
    $count = $autoTag->processAllVariants(false);
    $simulationLogs = $autoTag->logs;

    setEventMessage("Traitement terminé : $count variante(s) traitée(s), "
        .$autoTag->created_categories." catégorie(s) créée(s), "
        .$autoTag->assigned_products." assignation(s)", 'mesgs');

    if (!empty($autoTag->errors)) {
        foreach ($autoTag->errors as $err) {
            setEventMessage($err, 'errors');
        }
    }
}

// Exécution pour un parent
if ($action == 'execute_parent' && $parentId > 0) {
    $autoTag = new AutoTagVariant($db);
    $count = $autoTag->processAllVariantsOfParent($parentId, false);
    $simulationLogs = $autoTag->logs;

    setEventMessage("Traitement terminé : $count variante(s) traitée(s), "
        .$autoTag->created_categories." catégorie(s) créée(s), "
        .$autoTag->assigned_products." assignation(s)", 'mesgs');
}

// Nettoyage catégories vides
if ($action == 'clean_empty') {
    $autoTag = new AutoTagVariant($db);
    $deleted = $autoTag->cleanEmptyCategories(0, false);
    $simulationLogs = $autoTag->logs;
    setEventMessage("$deleted catégorie(s) vide(s) supprimée(s)", 'mesgs');
}

// Simulation nettoyage
if ($action == 'simulate_clean') {
    $autoTag = new AutoTagVariant($db);
    $deleted = $autoTag->cleanEmptyCategories(0, true);
    $simulationLogs = $autoTag->logs;
}

// =========================================================================
// PAGE
// =========================================================================

$page_name = "Configuration EasyVariant";
llxHeader('', $page_name);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($page_name, $linkback, 'setup');

print '<div class="tabBar">';

// ==========================================================================
// SECTION 1 : Configuration générale
// ==========================================================================

print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_config">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Configuration générale</td></tr>';

// EasyVariant debug
print '<tr class="oddeven"><td>Mode debug (filtrage attributs)</td><td>';
$checked = !empty($conf->global->EASYVARIANT_DEBUG) ? 'checked="checked"' : '';
print '<input type="checkbox" name="EASYVARIANT_DEBUG" value="1" '.$checked.'>';
print ' <span class="opacitymedium">Logs de debug JS dans la console navigateur</span>';
print '</td></tr>';

// AutoTag activé
print '<tr class="oddeven"><td><strong>Auto-catégorisation activée</strong></td><td>';
$checked = !empty($conf->global->AUTOTAGVARIANT_ENABLED) ? 'checked="checked"' : '';
print '<input type="checkbox" name="AUTOTAGVARIANT_ENABLED" value="1" '.$checked.'>';
print ' <span class="opacitymedium">Catégoriser automatiquement les variantes à la création/modification</span>';
print '</td></tr>';

// AutoTag debug
print '<tr class="oddeven"><td>Mode debug (auto-catégorisation)</td><td>';
$checked = !empty($conf->global->AUTOTAGVARIANT_DEBUG) ? 'checked="checked"' : '';
print '<input type="checkbox" name="AUTOTAGVARIANT_DEBUG" value="1" '.$checked.'>';
print ' <span class="opacitymedium">Logs dans dolibarr.log (dol_syslog)</span>';
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin:10px 0;"><input type="submit" class="button" value="Sauvegarder la configuration"></div>';
print '</form>';

print '<br>';

// ==========================================================================
// SECTION 2 : Traitement par lot
// ==========================================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3">Auto-catégorisation par lot</td></tr>';

// Traitement global
print '<tr class="oddeven">';
print '<td><strong>Toutes les variantes</strong><br><span class="opacitymedium">Traite toutes les variantes dont le parent a un template configuré</span></td>';
print '<td class="center">';
print '<a class="button" href="'.$_SERVER["PHP_SELF"].'?action=simulate_all&token='.newToken().'">Simuler</a>';
print '</td>';
print '<td class="center">';
print '<a class="button button-delete" href="'.$_SERVER["PHP_SELF"].'?action=execute_all&token='.newToken().'" onclick="return confirm(\'Traiter toutes les variantes ? Cette action va créer des catégories et assigner les produits.\');">Exécuter</a>';
print '</td>';
print '</tr>';

// Traitement par parent
print '<tr class="oddeven">';
print '<td><strong>Variantes d\'un produit parent</strong><br><span class="opacitymedium">Entrer l\'ID du produit parent</span></td>';
print '<td class="center">';
print '<form method="get" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
print '<input type="hidden" name="action" value="simulate_parent">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="number" name="parent_id" placeholder="ID parent" style="width:100px;" required> ';
print '<input type="submit" class="button" value="Simuler">';
print '</form>';
print '</td>';
print '<td class="center">';
print '<form method="get" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
print '<input type="hidden" name="action" value="execute_parent">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="number" name="parent_id" placeholder="ID parent" style="width:100px;" required> ';
print '<input type="submit" class="button button-delete" value="Exécuter" onclick="return confirm(\'Traiter les variantes de ce parent ?\');">';
print '</form>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';

// ==========================================================================
// SECTION 3 : Nettoyage
// ==========================================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3">Nettoyage des catégories vides</td></tr>';

print '<tr class="oddeven">';
print '<td><strong>Supprimer les catégories produit vides</strong><br><span class="opacitymedium">Catégories sans produit ET sans sous-catégorie (nettoyage bottom-up)</span></td>';
print '<td class="center">';
print '<a class="button" href="'.$_SERVER["PHP_SELF"].'?action=simulate_clean&token='.newToken().'">Simuler</a>';
print '</td>';
print '<td class="center">';
print '<a class="button button-delete" href="'.$_SERVER["PHP_SELF"].'?action=clean_empty&token='.newToken().'" onclick="return confirm(\'Supprimer les catégories produit vides ? Action irréversible.\');">Nettoyer</a>';
print '</td>';
print '</tr>';
print '</table>';

print '<br>';

// ==========================================================================
// RÉSULTATS SIMULATION / EXÉCUTION
// ==========================================================================

if (!empty($simulationLogs)) {
    $isDryRun = in_array($action, array('simulate_all', 'simulate_parent', 'simulate_clean'));
    $title = $isDryRun ? 'Résultat de la simulation' : 'Journal d\'exécution';
    $bgColor = $isDryRun ? '#fff3cd' : '#d4edda';
    $borderColor = $isDryRun ? '#ffc107' : '#c3e6cb';

    print '<div style="background: '.$bgColor.'; border: 1px solid '.$borderColor.'; border-radius: 6px; padding: 15px; margin: 20px 0; max-height: 500px; overflow-y: auto;">';
    print '<h3 style="margin-top:0;">'.$title.'</h3>';
    print '<pre style="margin:0; white-space: pre-wrap; font-size: 12px; font-family: monospace;">';
    foreach ($simulationLogs as $log) {
        print dol_escape_htmltag($log)."\n";
    }
    print '</pre>';
    print '</div>';
}

// ==========================================================================
// SECTION 4 : Statistiques
// ==========================================================================

$autoTag = new AutoTagVariant($db);
$stats = $autoTag->getStats();

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Statistiques</td></tr>';
print '<tr class="oddeven"><td>Produits parents avec template configuré</td><td><strong>'.$stats['parents_with_template'].'</strong></td></tr>';
print '<tr class="oddeven"><td>Variantes totales</td><td><strong>'.$stats['total_variants'].'</strong></td></tr>';
print '<tr class="oddeven"><td>Variantes éligibles (parent avec template)</td><td><strong>'.$stats['variants_with_template'].'</strong></td></tr>';
print '<tr class="oddeven"><td>Auto-catégorisation</td><td><span style="color: '.(!empty($conf->global->AUTOTAGVARIANT_ENABLED) ? '#28a745' : '#dc3545').';">'.(!empty($conf->global->AUTOTAGVARIANT_ENABLED) ? 'Activée' : 'Désactivée').'</span></td></tr>';
print '<tr class="oddeven"><td>Version</td><td>2.0.0</td></tr>';
print '</table>';

print '<br>';

// ==========================================================================
// SECTION 5 : Aide / Documentation
// ==========================================================================

print '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin: 20px 0;">';

print '<h3>Fonctionnement de l\'auto-catégorisation</h3>';

print '<h4>Principe</h4>';
print '<p>Quand une variante est créée ou modifiée, le module :</p>';
print '<ol>';
print '<li>Lit les <strong>attributs de la combinaison</strong> depuis la base (pas de parsing de la ref)</li>';
print '<li>Les <strong>ordonne selon le template</strong> EasyVariant du produit parent (extrafield <code>vartemplate</code>)</li>';
print '<li>Récupère les <strong>catégories du parent</strong></li>';
print '<li>Crée l\'<strong>arborescence de sous-catégories</strong> si elle n\'existe pas</li>';
print '<li>Assigne la variante à la <strong>catégorie feuille uniquement</strong></li>';
print '</ol>';

print '<h4>Exemple</h4>';
print '<table class="noborder" style="width:auto;">';
print '<tr><td style="padding:3px 15px 3px 0;">Produit parent :</td><td><code>MO</code> dans catégorie <code>Plafond/MOLENE</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Template :</td><td><code>3.Largeur, 7.Type, 8.Épaisseur, 9.Laquage, 10.Couleur, 11.Perforation</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Variante :</td><td><code>MO_300_2INT_0.80_PRE_RAL_1015_23%2.5</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Catégorie créée :</td><td><code>Plafond/MOLENE/300/2INT/0.80/PRE/RAL_1015/23%2.5</code></td></tr>';
print '</table>';

print '<h4>Changement de template</h4>';
print '<p>Si le template du parent est modifié, le trigger <code>PRODUCT_MODIFY</code> <strong>recatégorise automatiquement toutes les variantes</strong> de ce parent. Les anciennes assignations dans les sous-catégories sont retirées avant de créer les nouvelles.</p>';
print '<p>Les catégories devenues vides ne sont <strong>pas supprimées automatiquement</strong> (sécurité). Utilisez le bouton "Nettoyer" ci-dessus.</p>';

print '<h4>Configuration de l\'extrafield (rappel)</h4>';
print '<ul>';
print '<li><strong>Code :</strong> <code>vartemplate</code></li>';
print '<li><strong>Type :</strong> Liste de sélection (n choix) issue d\'une table</li>';
print '<li><strong>Valeur :</strong> <code>product_attribute:CONCAT(position, \'.\', label):rowid::</code></li>';
print '</ul>';
print '<p><a href="'.DOL_URL_ROOT.'/product/admin/product_extrafields.php" class="button">Voir/Modifier les extrafields produits</a></p>';

print '</div>';

print '</div>'; // tabBar

llxFooter();
$db->close();
```

## FICHIER 5 (MODIFIER) : easyvariant/lang/fr_FR/easyvariant.lang

Remplacer entièrement le contenu par :

```
# EasyVariant - French Language File
# @version 2.0.0

# Module
Module500200Name=EasyVariant
Module500200Desc=Filtrage et catégorisation automatique des variantes produits

# General
EasyVariant=EasyVariant
EasyVariantSetup=Configuration EasyVariant
EasyVariantAbout=À propos d'EasyVariant

# Configuration - Filtrage
EasyVariantDebugMode=Mode debug
EasyVariantDebugModeHelp=Affiche des informations de debug dans la console du navigateur
EasyVariantSave=Sauvegarder
EasyVariantConfigUpdated=Configuration mise à jour avec succès

# Configuration - AutoTag
AutoTagVariantEnabled=Auto-catégorisation activée
AutoTagVariantDebug=Mode debug auto-catégorisation
AutoTagVariantConfigSaved=Configuration sauvegardée

# Messages - Filtrage
EasyVariantFilteringActive=EasyVariant actif : %s attribut(s) configuré(s) pour ce produit
EasyVariantNoTemplate=Aucun template d'attributs configuré pour ce produit
EasyVariantFilterApplied=Filtrage des attributs appliqué

# Messages - AutoTag
AutoTagProcessing=Traitement en cours...
AutoTagCompleted=Traitement terminé : %s variante(s) traitée(s)
AutoTagCategoriesCreated=%s catégorie(s) créée(s)
AutoTagProductsAssigned=%s assignation(s)
AutoTagSimulation=Simulation (aucune modification)
AutoTagCleanEmpty=Nettoyage des catégories vides

# Batch
AutoTagBatchAll=Toutes les variantes
AutoTagBatchParent=Variantes d'un produit parent
AutoTagSimulate=Simuler
AutoTagExecute=Exécuter
AutoTagClean=Nettoyer

# Help
EasyVariantHowItWorks=Comment ça fonctionne
EasyVariantStep1=Configuration du produit : Sur la fiche produit, utilisez l'extrafield 'Template de variantes' pour sélectionner les attributs autorisés
EasyVariantStep2=Filtrage automatique : Lors de la création d'une nouvelle variante, seuls les attributs sélectionnés s'affichent
EasyVariantStep3=Catégorisation automatique : La variante est placée dans une arborescence de catégories selon ses attributs

# Extrafield config
EasyVariantExtrafieldConfig=Configuration de l'extrafield
EasyVariantExtrafieldCode=Code : vartemplate
EasyVariantExtrafieldType=Type : Liste de sélection (n choix) issue d'une table
EasyVariantExtrafieldValue=Valeur : product_attribute:CONCAT(position, '.', label):rowid::

# Statistics
EasyVariantStatistics=Statistiques
EasyVariantStatsProductsWithTemplate=Produits avec template configuré
EasyVariantStatsTotalVariants=Variantes totales
EasyVariantStatsEligibleVariants=Variantes éligibles (parent avec template)

# Permissions
Permission500200=Utiliser EasyVariant
```

## FICHIER 6 (MODIFIER) : easyvariant/README.md

Remplacer entièrement le contenu par :

```markdown
# EasyVariant - Module Dolibarr v2.0.0

Module Dolibarr pour la gestion avancée des variantes produit :
1. **Filtrage intelligent** des attributs de variantes selon un template
2. **Catégorisation automatique** des variantes dans une arborescence de catégories

Testé sur Dolibarr 20.0.0.

---

## Fonctionnalité 1 : Filtrage des attributs (v1.x)

Filtre les attributs de variantes affichés sur la page `/variants/combinations.php` selon le template configuré sur chaque produit parent (extrafield `vartemplate`).

### Utilisation
1. Sur la fiche produit parent, sélectionner les attributs dans l'extrafield "Template de variantes"
2. Lors de la création de variantes, seuls les attributs sélectionnés s'affichent

---

## Fonctionnalité 2 : Auto-catégorisation (v2.0)

Crée automatiquement une arborescence de catégories basée sur les attributs de chaque variante.

### Principe

Quand une variante est créée ou modifiée :
1. Le trigger lit les **attributs de la combinaison** depuis la table `llx_product_attribute_combination2val`
2. Les ordonne selon le **template** du parent (extrafield `vartemplate`)
3. Récupère les **catégories du parent**
4. Crée l'**arborescence de sous-catégories** manquante
5. Assigne la variante à la **catégorie feuille uniquement**

### Exemple concret

```
Produit parent :  MO → catégorie Plafond/MOLENE
Template :        3.Largeur, 7.Type, 8.Épaisseur, 9.Laquage, 10.Couleur, 11.Perforation

Variante MO_300_2INT_0.80_PRE_RAL_1015_23%2.5
  → Attributs lus depuis la base : 300, 2INT, 0.80, PRE, RAL_1015, 23%2.5
  → Catégorie créée : Plafond/MOLENE/300/2INT/0.80/PRE/RAL_1015/23%2.5
  → Variante assignée dans : 23%2.5 (feuille uniquement)

Variante MO_200_1EXT_0.70_PRE_RAL_9010_NP
  → Catégorie : Plafond/MOLENE/200/1EXT/0.70/PRE/RAL_9010/NP
```

Si le parent est dans **plusieurs catégories**, l'arborescence est dupliquée sous chacune.

### Changement de template

Si le template du parent est modifié, le trigger `PRODUCT_MODIFY` **recatégorise automatiquement toutes les variantes** :
- Les anciennes assignations dans les sous-catégories sont retirées
- Les nouvelles catégories sont créées selon le nouveau template
- Les catégories devenues vides ne sont **pas supprimées automatiquement** (sécurité)
- Un bouton "Nettoyer" est disponible dans la page admin

### Traitement par lot (batch)

Page admin (`Configuration → Modules → EasyVariant → Configurer`) :
- **Simuler** : aperçu des catégories qui seraient créées (aucune modification)
- **Exécuter** : traitement réel avec création des catégories et assignations
- **Nettoyer** : suppression des catégories produit vides (bottom-up)
- Possibilité de traiter toutes les variantes ou celles d'un parent spécifique

---

## Configuration technique

### Extrafield requis (sur les produits)
- **Code** : `vartemplate`
- **Type** : Liste de sélection (n choix) issue d'une table
- **Valeur** : `product_attribute:CONCAT(position, '.', label):rowid::`

### Constantes de configuration
| Constante | Défaut | Description |
|---|---|---|
| `EASYVARIANT_DEBUG` | 0 | Logs JS dans la console navigateur |
| `AUTOTAGVARIANT_ENABLED` | 1 | Active/désactive le trigger auto-catégorisation |
| `AUTOTAGVARIANT_DEBUG` | 0 | Logs dans dolibarr.log |

### Structure des fichiers
```
easyvariant/
├── admin/admin.php                                           # Page de configuration
├── ajax/save_order.php                                       # Sauvegarde ordre attributs
├── class/
│   ├── actions_easyvariant.class.php                         # Hook filtrage attributs
│   └── autotagvariant.class.php                              # Logique auto-catégorisation
├── core/
│   ├── hooks/                                                # Hooks alternatifs
│   ├── modules/modEasyVariant.class.php                      # Descripteur module
│   └── triggers/
│       └── interface_99_modEasyVariant_AutoTagVariant.class.php  # Trigger PRODUCT_CREATE/MODIFY
├── css/easyvariant.css                                       # Styles
├── js/easyvariant.js                                         # Filtrage JavaScript
└── lang/fr_FR/easyvariant.lang                               # Traductions
```

### Tables Dolibarr utilisées (lecture seule)
- `llx_product_attribute_combination` : lien parent ↔ variante
- `llx_product_attribute_combination2val` : valeurs attributs d'une combinaison
- `llx_product_attribute` : définition des attributs (label, position)
- `llx_product_attribute_value` : valeurs possibles des attributs (ref, value)
- `llx_product_extrafields` : extrafield `vartemplate`
- `llx_categorie` / `llx_categorie_product` : catégories et assignations

---

## Debug

### Console navigateur (filtrage)
```javascript
window.easyVariantDebug.config()    // Voir la configuration
window.easyVariantDebug.showAll()   // Afficher tous les attributs
window.easyVariantDebug.reapply()   // Réappliquer le filtrage
```

### Logs serveur (auto-catégorisation)
Activer `AUTOTAGVARIANT_DEBUG` dans la config, puis consulter `dolibarr.log` :
```
grep "AutoTagVariant" /path/to/documents/dolibarr.log
```

---

## Installation

1. Copier le dossier `easyvariant/` dans `htdocs/custom/`
2. Vérifier que `$dolibarr_main_document_root_alt` pointe vers `htdocs/custom` dans `conf.php`
3. Activer le module dans Configuration → Modules
4. **Désactiver puis réactiver** le module si mise à jour depuis v1.x (pour enregistrer les triggers)
5. Créer l'extrafield `vartemplate` si pas encore fait

---

**Version** : 2.0.0
**Compatibilité** : Dolibarr 20.0.0+
**Licence** : GPL-3+
```

## Message de commit

```
feat: ajout auto-catégorisation des variantes dans arborescence de catégories

- Nouveau: trigger PRODUCT_CREATE/MODIFY pour catégoriser automatiquement les variantes
- Nouveau: classe AutoTagVariant avec logique complète (processVariant, batch, nettoyage)
- Nouveau: page admin avec simulation, exécution par lot, nettoyage catégories vides
- Modifié: descripteur module v2.0.0 avec triggers et dépendance modCategorie
- Modifié: lang fr_FR au format .lang correct
- Gestion du changement de template: recatégorisation auto de toutes les variantes du parent
```

## Résumé des changements

- **autotagvariant.class.php** (NOUVEAU, 668 lignes) : classe helper avec toute la logique de catégorisation. Lit les attributs depuis llx_product_attribute_combination2val, les ordonne selon le template vartemplate du parent, crée l'arborescence de sous-catégories sous les catégories du parent, assigne la variante à la feuille uniquement. Inclut aussi : batch processing, simulation/dry-run, nettoyage catégories vides, stats.

- **interface_99_modEasyVariant_AutoTagVariant.class.php** (NOUVEAU, 136 lignes) : trigger Dolibarr sur PRODUCT_CREATE et PRODUCT_MODIFY. Si variante → catégorise. Si parent avec template modifié → recatégorise toutes ses variantes.

- **modEasyVariant.class.php** (MODIFIÉ) : version 2.0.0, ajout triggers=>1 dans module_parts, ajout dépendance modCategorie, ajout constantes AUTOTAGVARIANT_ENABLED et AUTOTAGVARIANT_DEBUG dans init().

- **admin.php** (MODIFIÉ) : page admin complète avec config, batch simuler/exécuter, nettoyage, logs, stats, documentation.

- **easyvariant.lang** (MODIFIÉ) : corrigé au format .lang Dolibarr (était en PHP), ajout clés AutoTag.

- **README.md** (MODIFIÉ) : documentation complète v2.0.0.
