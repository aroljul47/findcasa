# Déploiement de FindCasa sur Render

Le projet se déploie comme deux services Render : l’API Laravel (`findcasa-api`) et le site statique Vue (`findcasa-frontend`). Le projet utilise **MySQL**. Render ne propose pas de base MySQL managée : il faut donc fournir un serveur MySQL externe, accessible depuis Internet et autorisant les connexions depuis Render.

## 1. Préparer le dépôt

1. Pousse le projet sur un dépôt GitHub ou GitLab connecté à Render.
2. Ne commite aucun fichier `.env` et ne copie pas de secret depuis les fichiers locaux.
3. Dans Render, choisis **New > Blueprint**, connecte le dépôt et sélectionne `render.yaml` à la racine.
4. Render créera le service Docker de l’API et le site statique. Le blueprint demandera les variables marquées `sync: false`.

Le Dockerfile de l’API utilise PHP 8.3 avec `pdo_mysql`, pointe Apache vers `backend/public` et écoute sur le port fourni par Render. Le frontend est compilé depuis `frontend` avec Vite et publié depuis `frontend/dist`.

## 2. Variables de l’API

Renseigne les valeurs suivantes dans les variables du service `findcasa-api` :

- `APP_KEY` : génère une clé depuis `backend` avec `php artisan key:generate --show`, puis colle la valeur complète commençant par `base64:`. Garde-la secrète et stable entre les déploiements.
- `APP_URL` : URL publique de l’API, par exemple `https://findcasa-api.onrender.com`.
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` : paramètres de ta base MySQL distante.
- `DB_PORT` : port fourni par l’hébergeur MySQL (souvent `3306`).
- `FRONTEND_URL` : URL publique Render du site, par exemple `https://findcasa-frontend.onrender.com`.
- `CORS_ALLOWED_ORIGINS` : même origine frontend, sans slash final. Si tu utilises un domaine personnalisé, ajoute son origine ici.

Le blueprint configure déjà `DB_CONNECTION=mysql`, `APP_ENV=production` et `APP_DEBUG=false`. N’utilise pas la base SQLite du développement en production. Si ton fournisseur MySQL impose TLS, confirme le paramétrage SSL correspondant avec lui avant la mise en production.

## 3. Variables du frontend

Dans les variables du service `findcasa-frontend`, définis :

```text
VITE_API_URL=https://findcasa-api.onrender.com/api
VITE_BACKEND_URL=https://findcasa-api.onrender.com
```

Remplace les hôtes par les URL publiques réellement attribuées dans Render. Les valeurs `VITE_*` sont intégrées au moment du build : après leur modification, déclenche un nouveau déploiement du frontend.

Le blueprint ajoute une règle de réécriture vers `/index.html` pour que les routes de l’application Vue fonctionnent lors d’un accès direct. Le service API vérifie sa santé avec la route Laravel `/up`.

## 4. Migrations MySQL

Après avoir renseigné les variables et avant d’utiliser l’application, exécute une seule fois contre la base de production :

```sh
cd backend
php artisan migrate --force
```

Tu peux utiliser un Shell Render si ton type de service le permet, ou exécuter la commande depuis un environnement local configuré pour joindre la base de production. N’exécute pas les seeders sans vérifier les données qu’ils créent. Pour un service Render payant, tu peux configurer `php artisan migrate --force` comme **Pre-Deploy Command** du service ; cette option n’est pas disponible pour les services web gratuits.

## 5. Vérifications après déploiement

- Ouvre `https://<api>/up` : Laravel doit répondre avec succès.
- Ouvre le domaine du frontend et recharge une route interne directement.
- Dans les outils réseau du navigateur, vérifie que les appels partent vers `https://<api>/api` et qu’il n’y a pas d’erreur CORS.
- Teste la connexion à MySQL ainsi que l’inscription et la connexion avec des données de test.

## Limites à configurer avant la production

- **Uploads** : le système écrit les photos de logement sur le disque `public` et les pièces d’identité sur le disque `local`. Le disque du conteneur n’est pas une solution de stockage durable : les fichiers peuvent disparaître lors d’un redéploiement. Configure un stockage objet compatible S3, ou un disque persistant adapté, avant de permettre des uploads réels.
- **Temps réel** : Reverb n’est pas activé par défaut par ce déploiement. `BROADCAST_CONNECTION=log` évite que Laravel tente de se connecter à un serveur Reverb inexistant; la messagerie temps réel ne fonctionnera pas tant que Reverb et ses variables `REVERB_*` / `VITE_REVERB_*` ne seront pas configurés.
- **Tâches et files** : le planificateur Laravel doit exécuter les commandes de `backend/routes/console.php`, et la queue utilise la base MySQL. Ajoute un worker et un cron Render dédiés si ces traitements sont nécessaires. Le seul service web ne les exécute pas en continu.
- **MySQL externe** : vérifie que le fournisseur autorise les connexions entrantes depuis Render, que la base est sauvegardée et que la connexion est chiffrée si le fournisseur l’exige.
