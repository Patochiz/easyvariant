/**
 * EasyVariant JavaScript - Version finale
 * 
 * @package EasyVariant
 * @author  Claude AI
 * @version 1.0.5-FINAL
 */

// Configuration globale - ÉVITER LA RE-DÉCLARATION
if (typeof window.easyVariantConfig === 'undefined') {
    window.easyVariantConfig = {};
}

// Variables globales avec protection
if (typeof window.easyVariantInitialized === 'undefined') {
    window.easyVariantInitialized = false;
}

/**
 * Initialise le filtrage des attributs de variantes
 */
function initEasyVariantFiltering() {
    // Éviter la double initialisation
    if (window.easyVariantInitialized) {
        return;
    }
    
    if (!window.easyVariantConfig.allowedAttributes) {
        return;
    }
    
    // Convertir la chaîne en tableau d'IDs
    const allowedIds = window.easyVariantConfig.allowedAttributes.split(',').map(id => parseInt(id.trim()));
    
    // Appliquer le filtrage
    applyAttributeFiltering(allowedIds);
    
    // Marquer comme initialisé
    window.easyVariantInitialized = true;
    
    // Afficher notification discrète
    showFilteringNotification(allowedIds.length);
}

/**
 * Applique le filtrage des attributs dans les formulaires
 * 
 * @param {Array} allowedIds IDs des attributs autorisés
 */
function applyAttributeFiltering(allowedIds) {
    // Sélecteur précis - SEULEMENT les attributs
    const attributeSelector = 'select[name="attribute"]';
    
    $(attributeSelector).each(function() {
        const selectElement = $(this);
        
        // Filtrer les options d'ATTRIBUTS seulement
        selectElement.find('option').each(function() {
            const option = $(this);
            const optionValue = parseInt(option.val());
            
            // Garder l'option vide et les options autorisées
            if (option.val() === '' || option.val() === '0' || isNaN(optionValue) || allowedIds.includes(optionValue)) {
                option.show();
                option.prop('disabled', false);
            } else {
                option.hide();
                option.prop('disabled', true);
            }
        });
        
        // Marquer comme filtré pour le style
        selectElement.addClass('easyvariant-filtered');
    });
}

/**
 * Affiche une notification discrète du filtrage
 * 
 * @param {number} attributeCount Nombre d'attributs autorisés
 */
function showFilteringNotification(attributeCount) {
    // Éviter les notifications multiples
    if ($('.easyvariant-notification').length > 0) {
        return;
    }
    
    // Créer une notification discrète
    const notification = $(`
        <div class="easyvariant-notification">
            <span class="easyvariant-icon">🎯</span>
            <span class="easyvariant-text">
                EasyVariant : ${attributeCount} attribut(s) configuré(s) pour ce produit
            </span>
            <button class="easyvariant-close" onclick="$(this).parent().fadeOut()">×</button>
        </div>
    `);
    
    // Ajouter au début du contenu principal
    $('.fiche, .card, .page-content').first().prepend(notification);
    
    // Auto-masquer après 4 secondes
    setTimeout(() => {
        notification.fadeOut();
    }, 4000);
}

/**
 * Utilitaires de debug (en mode production simplifié)
 */
if (typeof window.easyVariantDebug === 'undefined') {
    window.easyVariantDebug = {
        showAll: function() {
            $('select[name="attribute"] option').show().prop('disabled', false);
            $('.easyvariant-filtered').removeClass('easyvariant-filtered');
            console.log('EasyVariant: Tous les attributs affichés');
        },
        
        reapply: function() {
            if (window.easyVariantConfig.allowedAttributes) {
                const allowedIds = window.easyVariantConfig.allowedAttributes.split(',').map(id => parseInt(id.trim()));
                applyAttributeFiltering(allowedIds);
                console.log('EasyVariant: Filtrage réappliqué');
            }
        },
        
        config: function() {
            return window.easyVariantConfig;
        }
    };
}
