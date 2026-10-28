const labels = {
  pending: 'En attente',
  accepted: 'Acceptée',
  confirmed: 'Confirmée',
  rejected: 'Refusée',
  expired: 'Expirée',
  cancelled: 'Annulée',
  completed: 'Terminée',
  published: 'Publiée',
  draft: 'Brouillon',
  available: 'Disponible',
  reserved: 'Réservée',
  rented: 'Louée',
  occupied: 'Occupée',
  investigating: 'En cours d’examen',
  resolved: 'Résolue',
  approved: 'Approuvée',
  verified: 'Vérifié',
  not_applicable: 'Non applicable',
  priority: 'Prioritaire',
  review: 'À vérifier',
  incomplete: 'Incomplète',
  failed: 'Échec',
  started: 'En cours',
}

export function statusLabel(status) {
  return labels[status] || 'Statut inconnu'
}

export function statusTone(status) {
  if (['accepted', 'confirmed', 'published', 'available', 'approved', 'verified', 'completed', 'resolved'].includes(status)) return 'success'
  if (['rejected', 'expired', 'cancelled', 'failed'].includes(status)) return 'danger'
  if (['reserved', 'rented', 'occupied'].includes(status)) return 'info'
  return 'warning'
}
