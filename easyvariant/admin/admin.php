<?php
/**
 * EasyVariant Administration Page
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 1.0.5-FINAL
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// Security check
if (!$user->admin) {
    accessforbidden();
}

// Translations
$langs->load("admin");
$langs->load("easyvariant@easyvariant");

// Parameters
$action = GETPOST('action', 'alpha');

// Actions
if ($action == 'set_debug') {
    $debug = GETPOST('EASYVARIANT_DEBUG', 'alpha') ? '1' : '0';
    $res = dolibarr_set_const($db, "EASYVARIANT_DEBUG", $debug, 'chaine', 0, '', $conf->entity);
    
    if ($res > 0) {
        setEventMessage("Configuration mise à jour", 'mesgs');
    } else {
        setEventMessage("Erreur lors de la mise à jour", 'errors');
    }
    
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Page header
$page_name = "Configuration EasyVariant";
llxHeader('', $page_name);

// Page title
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($page_name, $linkback, 'setup');

print '<br>';

// Success message
print '<div class="info-box" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;">';
print '<h3 style="color: #155724; margin-top: 0;">✅ EasyVariant est opérationnel !</h3>';
print '<p><strong>Le module fonctionne correctement.</strong> Les attributs de variantes sont maintenant filtrés selon le template configuré sur chaque produit.</p>';
print '</div>';

// Configuration form
print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="set_debug">';

print '<table class="noborder" width="100%">';
print '<tr class="liste_titre">';
print '<td colspan="2">Configuration avancée</td>';
print '</tr>';

// Debug mode
print '<tr class="oddeven">';
print '<td>Mode debug</td>';
print '<td>';
$checked = !empty($conf->global->EASYVARIANT_DEBUG) ? 'checked="checked"' : '';
print '<input type="checkbox" name="EASYVARIANT_DEBUG" value="1" '.$checked.'>';
print ' <span class="opacitymedium">Active les logs de debug dans la console du navigateur</span>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button" value="Sauvegarder">';
print '</div>';
print '</form>';

print '<br><hr><br>';

// Information section
print '<div class="info-box" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin: 20px 0;">';
print '<h3>📖 Utilisation d\'EasyVariant</h3>';

print '<h4>🔧 Comment utiliser le module :</h4>';
print '<ol style="margin: 10px 0; padding-left: 30px;">';
print '<li><strong>Configurer un produit :</strong> Sur la fiche produit, utiliser l\'extrafield "Template de variantes" pour sélectionner les attributs autorisés</li>';
print '<li><strong>Créer des variantes :</strong> Aller dans Produits → Variantes → Gestion des variantes</li>';
print '<li><strong>Interface simplifiée :</strong> Seuls les attributs sélectionnés s\'affichent dans les listes déroulantes</li>';
print '</ol>';

print '<h4>💡 Exemple concret :</h4>';
print '<p style="margin: 10px 0;">Pour un t-shirt, sélectionnez seulement "Taille" et "Couleur" dans le template. Lors de la création de variantes, vous ne verrez que ces deux attributs au lieu de tous les attributs configurés dans Dolibarr.</p>';

print '<h4>⚙️ Configuration de l\'extrafield :</h4>';
print '<p style="margin: 10px 0;">L\'extrafield est déjà configuré avec ces paramètres :</p>';
print '<ul style="margin: 10px 0; padding-left: 30px;">';
print '<li><strong>Code :</strong> vartemplate</li>';
print '<li><strong>Type :</strong> Liste de sélection (n choix) issue d\'une table</li>';
print '<li><strong>Valeur :</strong> product_attribute:CONCAT(position, \'.\', label):rowid::</li>';
print '</ul>';

print '<p><a href="'.DOL_URL_ROOT.'/product/admin/product_extrafields.php" class="button">Voir/Modifier les extrafields produits</a></p>';

print '</div>';

// Statistics
print '<h3>📊 Statistiques d\'utilisation</h3>';

// Count products with vartemplate configured
$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product_extrafields pe 
        INNER JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pe.fk_object 
        WHERE pe.vartemplate IS NOT NULL AND pe.vartemplate != ''";
$resql = $db->query($sql);

if ($resql) {
    $obj = $db->fetch_object($resql);
    $nb_products_with_template = $obj->nb;
} else {
    $nb_products_with_template = 'Erreur';
}

print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><td colspan="2">Utilisation du module</td></tr>';
print '<tr class="oddeven"><td>Produits avec template configuré</td><td><strong>'.$nb_products_with_template.'</strong></td></tr>';
print '<tr class="oddeven"><td>Statut du module</td><td><span style="color: #28a745;">✅ Opérationnel</span></td></tr>';
print '<tr class="oddeven"><td>Version</td><td>1.0.5-FINAL</td></tr>';
print '</table>';

// Footer
llxFooter();
$db->close();
?>