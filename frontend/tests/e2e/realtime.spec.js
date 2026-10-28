import { expect, test } from '@playwright/test'

async function login(page, email, password) {
  const response = await page.request.post('http://127.0.0.1:8000/api/auth/login', { data: { email, password } })
  expect(response.ok()).toBeTruthy()
  const body = await response.json()
  await page.addInitScript((token) => localStorage.setItem('findcasa_token', token), body.token)
  await page.goto('/espace')
  await expect(page.getByRole('heading', { name: /Bonjour/i })).toBeVisible({ timeout: 30000 })
}

test('un message arrive chez le propriétaire sans rechargement', async ({ browser }) => {
  test.skip(!process.env.E2E_STUDENT_EMAIL || !process.env.E2E_STUDENT_PASSWORD || !process.env.E2E_OWNER_EMAIL || !process.env.E2E_OWNER_PASSWORD, 'Identifiants démo absents')
  const ownerContext = await browser.newContext()
  const studentContext = await browser.newContext()
  const ownerPage = await ownerContext.newPage()
  const studentPage = await studentContext.newPage()
  await login(ownerPage, process.env.E2E_OWNER_EMAIL, process.env.E2E_OWNER_PASSWORD)
  await login(studentPage, process.env.E2E_STUDENT_EMAIL, process.env.E2E_STUDENT_PASSWORD)
  await ownerPage.getByRole('button', { name: 'Messages' }).click()

  const message = `Message temps réel ${Date.now()}`
  const result = await studentPage.evaluate(async ({ message }) => {
    const token = localStorage.getItem('findcasa_token')
    const properties = await fetch('http://127.0.0.1:8000/api/properties', { headers: { Accept: 'application/json' } }).then((response) => response.json())
    const property = properties.data.find((item) => item.owner_id)
    const response = await fetch(`http://127.0.0.1:8000/api/properties/${property.id}/messages`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ body: message }),
    })
    return { status: response.status, property: property.title }
  }, { message })

  expect(result.status).toBe(201)
  await expect(ownerPage.getByText(message)).toBeVisible({ timeout: 15000 })
  await ownerContext.close()
  await studentContext.close()
})
