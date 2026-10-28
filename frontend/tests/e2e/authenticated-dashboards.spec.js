import { expect, test } from '@playwright/test'

async function login(page, email, password) {
  await page.goto('/connexion')
  await page.getByRole('textbox', { name: 'Adresse email' }).fill(email)
  await page.getByRole('textbox', { name: 'Mot de passe' }).fill(password)
  await page.getByRole('button', { name: 'Se connecter' }).click()
  await expect(page).toHaveURL(/\/espace$/)
}

test('dashboard étudiant expose les parcours métier', async ({ page }) => {
  test.skip(!process.env.E2E_STUDENT_EMAIL || !process.env.E2E_STUDENT_PASSWORD, 'Identifiants étudiant absents')
  await login(page, process.env.E2E_STUDENT_EMAIL, process.env.E2E_STUDENT_PASSWORD)
  await expect(page.getByRole('button', { name: 'Mes favoris' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Mes réservations' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Mes avis' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Messages' })).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('button', { name: 'Notifications' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'À modérer' })).toHaveCount(0)
})

test('dashboard propriétaire affiche logements et demandes', async ({ page }) => {
  test.skip(!process.env.E2E_OWNER_EMAIL || !process.env.E2E_OWNER_PASSWORD, 'Identifiants propriétaire absents')
  await login(page, process.env.E2E_OWNER_EMAIL, process.env.E2E_OWNER_PASSWORD)
  await page.getByRole('button', { name: 'Mes logements' }).click()
  await expect(page.getByText('Chambre salon sanitaire à Zogbadjè')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Demandes reçues' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Messages' })).toBeVisible()
})

test('dashboard admin n’affiche aucun menu étudiant', async ({ page }) => {
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'Identifiants admin absents')
  await login(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASSWORD)
  await expect(page.getByRole('button', { name: 'À modérer' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Sources de collecte' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Gestion' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Mes favoris' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Mes réservations' })).toHaveCount(0)
  const moderationTab = page.getByRole('button', { name: 'À modérer' })
  await moderationTab.click()
  await expect(page).toHaveURL(/\/espace$/)
  await expect(moderationTab).toHaveClass(/active/)
  await expect(page.getByRole('button', { name: 'Se déconnecter' })).toBeVisible()
  await expect(page.getByRole('heading', { name: /Examiner avant publication/i })).toBeVisible()
  expect(await page.locator('.dashboard-header').evaluate((element) => getComputedStyle(element).position)).toBe('fixed')

  await page.getByRole('button', { name: 'Sources de collecte' }).click()
  await expect(page.getByRole('heading', { name: /Superviser la collecte/i })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Sources de collecte' })).toHaveClass(/active/)

  await page.getByRole('button', { name: 'Gestion' }).click()
  await expect(page.getByRole('heading', { name: /Piloter FindCasa/i })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Gestion' })).toHaveClass(/active/)

  await page.getByRole('navigation').getByRole('button', { name: 'Notifications' }).click()
  await expect(page.getByRole('heading', { name: 'Mes notifications.' })).toBeVisible()
  await expect(page.getByRole('navigation').getByRole('button', { name: 'Notifications' })).toHaveClass(/active/)

  await page.getByRole('button', { name: 'Mon profil' }).click()
  await expect(page.getByRole('heading', { name: 'Mon profil.' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Mon profil' })).toHaveClass(/active/)
})
