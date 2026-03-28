<?php
/**
 * EasyVariant Administration Page
 * Configuration + Auto-tagging batch processing
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

$simulationLogs = array();

// Simulation globale
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
print '<tr class="oddeven"><td><strong>Auto-tagging activé</strong></td><td>';
$checked = !empty($conf->global->AUTOTAGVARIANT_ENABLED) ? 'checked="checked"' : '';
print '<input type="checkbox" name="AUTOTAGVARIANT_ENABLED" value="1" '.$checked.'>';
print ' <span class="opacitymedium">Créer automatiquement les tags des variantes à la création/modification</span>';
print '</td></tr>';

// AutoTag debug
print '<tr class="oddeven"><td>Mode debug (auto-tagging)</td><td>';
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
print '<tr class="liste_titre"><td colspan="3">Auto-tagging par lot (variantes existantes)</td></tr>';

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
print '<tr class="oddeven"><td>Auto-tagging</td><td><span style="color: '.(!empty($conf->global->AUTOTAGVARIANT_ENABLED) ? '#28a745' : '#dc3545').';">'.(!empty($conf->global->AUTOTAGVARIANT_ENABLED) ? 'Activé' : 'Désactivé').'</span></td></tr>';
print '<tr class="oddeven"><td>Version</td><td>2.0.0</td></tr>';
print '</table>';

print '<br>';

// ==========================================================================
// SECTION 5 : Aide
// ==========================================================================

print '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; margin: 20px 0;">';

print '<h3>Fonctionnement de l\'auto-tagging</h3>';

print '<h4>Principe</h4>';
print '<p>Quand une variante est créée ou modifiée, le module :</p>';
print '<ol>';
print '<li>Lit les <strong>attributs de la combinaison</strong> depuis la base</li>';
print '<li>Les <strong>ordonne selon le template</strong> EasyVariant du produit parent (extrafield <code>vartemplate</code>)</li>';
print '<li>Récupère les <strong>catégories du parent</strong></li>';
print '<li>Crée les <strong>tags plats</strong> (sous-catégories directes) sous chaque catégorie du parent</li>';
print '<li>Assigne la variante à <strong>tous les tags</strong></li>';
print '</ol>';

print '<h4>Exemple</h4>';
print '<table class="noborder" style="width:auto;">';
print '<tr><td style="padding:3px 15px 3px 0;">Produit parent :</td><td><code>MO</code> dans catégorie <code>Plafond>>Molene</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Variante :</td><td><code>MO_200_1EXT_0.70_PRE_RAL_9010_NP</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Tags créés :</td><td><code>Plafond>>Molene>>200</code>, <code>Plafond>>Molene>>1EXT</code>, <code>Plafond>>Molene>>0.70</code>, <code>Plafond>>Molene>>PRE</code>, <code>Plafond>>Molene>>RAL_9010</code>, <code>Plafond>>Molene>>NP</code></td></tr>';
print '<tr><td style="padding:3px 15px 3px 0;">Assignation :</td><td>La variante est assignée à <strong>tous</strong> ces tags</td></tr>';
print '</table>';

print '<h4>Tags partagés</h4>';
print '<p>Les tags sont <strong>partagés entre variantes</strong>. Si deux variantes ont la valeur "PRE", elles partagent le même tag <code>Plafond>>Molene>>PRE</code>.</p>';

print '<h4>Traitement par lot</h4>';
print '<p>Utilisez la section "Auto-tagging par lot" ci-dessus pour <strong>tagger les variantes existantes</strong> créées avant l\'installation du module. Commencez par "Simuler" pour vérifier les tags qui seront créés, puis "Exécuter" pour les créer réellement.</p>';

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
