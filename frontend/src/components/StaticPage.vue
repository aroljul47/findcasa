<script setup>
import { ArrowLeft, Home, Mail, ShieldCheck } from 'lucide-vue-next'

defineProps({ page: { type: String, required: true } })
defineEmits(['back'])

const content = {
  conditions: {
    kicker: 'Informations légales',
    title: 'Conditions d’utilisation',
    intro: 'Ces règles encadrent l’utilisation de FindCasa par les étudiants, propriétaires et administrateurs.',
    sections: [
      ['Comptes utilisateurs', 'Chaque utilisateur fournit des informations exactes et protège ses identifiants. Un compte peut être suspendu en cas de fraude ou d’utilisation abusive.'],
      ['Annonces et réservations', 'Les propriétaires sont responsables des informations publiées. Toute annonce est vérifiée avant publication. Une réservation ne constitue pas un paiement de loyer.'],
      ['Comportement', 'Les contenus frauduleux, discriminatoires ou portant atteinte à la vie privée sont interdits.'],
    ],
  },
  confidentialite: {
    kicker: 'Protection des données',
    title: 'Politique de confidentialité',
    intro: 'FindCasa collecte uniquement les données nécessaires au fonctionnement de la plateforme.',
    sections: [
      ['Données collectées', 'Identité, coordonnées de contact, établissement, favoris, messages et demandes de réservation. Les pièces d’identité des propriétaires sont stockées de manière privée.'],
      ['Localisation', 'La position approximative sert au calcul de distance. L’adresse exacte n’est pas exposée dans le catalogue public.'],
      ['Conservation et sécurité', 'Les mots de passe sont hachés et les accès sont contrôlés par rôle. Les données ne sont pas vendues à des tiers.'],
    ],
  },
  aide: {
    kicker: 'Assistance FindCasa',
    title: 'Centre d’aide',
    intro: 'Les réponses aux questions essentielles pour utiliser la plateforme.',
    sections: [
      ['Comment réserver ?', 'Choisis une chambre disponible, indique ta date d’installation et envoie une demande au propriétaire.'],
      ['Comment publier ?', 'Crée un compte propriétaire, fais vérifier ton identité, puis complète les quatre étapes de publication.'],
      ['Comment signaler ?', 'Depuis une fiche logement, sélectionne le motif du signalement puis envoie-le à l’administration.'],
    ],
  },
  contact: {
    kicker: 'Nous contacter',
    title: 'Équipe FindCasa',
    intro: 'Pour toute difficulté, écris à l’équipe en précisant ton nom et le contexte de ta demande.',
    sections: [
      ['Email', 'aroldeha@gmail.com'],
      ['Téléphone', '01 57 72 88 07'],
      ['Adresse', 'Calavi Zogbadjè, Bénin'],
      ['Sécurité', 'Ne communique jamais ton mot de passe, tes codes secrets ou un paiement à une personne se présentant comme membre de FindCasa.'],
    ],
  },
}
</script>

<template>
  <div class="static-shell"><header class="static-header"><div class="container static-nav"><button class="back-brand" @click="$emit('back')"><span class="brand-mark"><Home :size="18" /></span><span>find<span>casa</span></span></button><button class="static-back" @click="$emit('back')"><ArrowLeft :size="14" /> Retour</button></div></header><main class="container static-main"><div class="section-kicker">{{ content[page].kicker }}</div><h1>{{ content[page].title }}</h1><p class="static-intro">{{ content[page].intro }}</p><div class="static-grid"><article v-for="section in content[page].sections" :key="section[0]"><span class="static-icon"><Mail v-if="page === 'contact'" :size="18" /><ShieldCheck v-else :size="18" /></span><h2>{{ section[0] }}</h2><p>{{ section[1] }}</p></article></div></main></div>
</template>
