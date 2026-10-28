import { expect, test } from '@playwright/test'

test('accueil et catalogue restent des écrans séparés', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: /Ton chez-toi/i })).toBeVisible()
  await page.getByRole('button', { name: /Rechercher/i }).click()
  await expect(page).toHaveURL(/\/annonces$/)
  await expect(page.getByRole('heading', { name: /Les logements qui/i })).toBeVisible()
  await expect(page.getByRole('heading', { name: /Ton chez-toi/i })).toHaveCount(0)
})

test('catalogue permet de filtrer et de revenir à l’accueil', async ({ page }) => {
  await page.goto('/annonces')
  await expect(page.getByText('Budget maximum')).toBeVisible()
  await expect(page.getByText('Type de logement')).toBeVisible()
  await page.getByRole('button', { name: 'Accueil' }).click()
  await expect(page).toHaveURL(/\/$/)
})

test('connexion et inscription sont séparées et légales', async ({ page }) => {
  await page.goto('/connexion')
  await expect(page.getByRole('heading', { name: /Connecte-toi/i })).toBeVisible()
  await expect(page.getByText(/Tu n’as pas encore de compte/i)).toBeVisible()
  await page.getByRole('button', { name: /Créer un compte/i }).last().click()
  await expect(page).toHaveURL(/\/inscription$/)
  await expect(page.getByRole('heading', { name: /Crée ton espace/i })).toBeVisible()
  await expect(page.getByRole('link', { name: /conditions d’utilisation/i })).toBeVisible()
  await expect(page.getByRole('link', { name: /politique de confidentialité/i })).toBeVisible()
})

test('les pages publiques ne débordent pas horizontalement', async ({ page }) => {
  for (const path of ['/', '/annonces', '/connexion', '/inscription']) {
    await page.goto(path)
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
    expect(overflow, `Débordement horizontal sur ${path}`).toBeFalsy()
  }
})

test('une fiche logement permet d’ouvrir et parcourir toutes les photos', async ({ page, request }) => {
  const catalogue = await request.get('http://127.0.0.1:8000/api/properties')
  expect(catalogue.ok()).toBeTruthy()
  const payload = await catalogue.json()
  const property = payload.data.find((item) => item.media?.filter((media) => media.kind === 'photo').length >= 2)
  test.skip(!property, 'Aucun logement avec deux photos approuvées dans la base')

  await page.goto(`/annonces/${property.id}`)
  await expect(page.getByRole('heading', { name: property.title })).toBeVisible()
  const thumbnails = page.getByRole('listitem')
  await expect(thumbnails).toHaveCount(property.media.filter((media) => media.kind === 'photo').length)
  await page.getByRole('button', { name: /Agrandir/i }).click()
  await expect(page.getByRole('dialog', { name: 'Galerie photos' })).toBeVisible()
  await page.keyboard.press('ArrowRight')
  await expect(page.getByText(`2 / ${await thumbnails.count()}`)).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', { name: 'Galerie photos' })).toHaveCount(0)
})
