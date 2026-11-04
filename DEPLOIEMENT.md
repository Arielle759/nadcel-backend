# Déploiement de Nadcel

Architecture en production :

| Élément | Hébergeur | Rôle |
|---|---|---|
| Site (Next.js) | Vercel, offre Hobby | Pages publiques et espaces client, gérant, admin |
| API (Laravel) | Railway, service `api` | API REST, photos des salons et prestations |
| Base de données | Railway, PostgreSQL | Données de l'application |
| Rappels de la veille | Railway, service `rappels` (cron) | `appointments:send-reminders` toutes les 15 min |
| E-mails | Brevo, offre gratuite | Envoi SMTP (300 e-mails par jour) |

Railway détecte Laravel automatiquement (pas de Dockerfile) et le sert avec
PHP-FPM et Caddy depuis `/app`.

---

## 1. Base de données

Railway, projet Nadcel : **New → Database → PostgreSQL**.
Utiliser une base **neuve** (ne pas réutiliser l'ancienne).

## 2. Service `api`

**New → GitHub Repo → `nadcel-backend`**, branche de production.

**Settings → Deploy → Pre-Deploy Command** :

```
sh ./railway/init-app.sh
```

(migrations, puis mise en cache de la configuration et des routes ; si une
étape échoue, l'ancienne version reste en ligne.)

**Settings → Networking → Generate Domain** : c'est l'adresse de l'API,
par exemple `https://nadcel-api.up.railway.app`.

**Volume (photos)** : clic droit sur le service → **Attach Volume**,
point de montage :

```
/app/public/storage
```

Les photos y sont écrites directement (variable `PUBLIC_DISK_ROOT`
ci-dessous) et servies sous `/storage/...` : elles survivent aux
redéploiements. Ne pas lancer `php artisan storage:link` en production.

Vérifier une fois que l'application est bien dans `/app` :
`railway ssh -s api -- pwd` doit afficher `/app`. Sinon, adapter le point
de montage et `PUBLIC_DISK_ROOT` au chemin affiché suivi de `/public/storage`.
Si l'envoi d'une photo échoue avec une erreur de permission, ajouter la
variable `RAILWAY_RUN_UID=0` (recommandation Railway pour les volumes).

**Variables** (onglet *Variables*) :

| Variable | Valeur |
|---|---|
| `APP_NAME` | `Nadcel` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | nouvelle clé : `php artisan key:generate --show` (en local) |
| `JWT_SECRET` | nouvelle clé : `php artisan jwt:secret --show` (en local) |
| `APP_URL` | adresse du service, ex. `https://nadcel-api.up.railway.app` |
| `FRONTEND_URL` | adresse Vercel, ex. `https://nadcel.vercel.app` |
| `APP_LOCALE` | `fr` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `PUBLIC_DISK_ROOT` | `/app/public/storage` |
| `QUEUE_CONNECTION` | `sync` (pas de processus de file d'attente à payer) |
| `LOG_CHANNEL` | `stderr` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | serveur SMTP indiqué par Brevo (`smtp-relay.brevo.com`) |
| `MAIL_PORT` | `587` |
| `MAIL_USERNAME` | identifiant SMTP Brevo (onglet *SMTP & API*) |
| `MAIL_PASSWORD` | **clé SMTP** Brevo (pas une clé API) |
| `MAIL_FROM_ADDRESS` | une adresse **vérifiée** comme expéditeur dans Brevo |
| `MAIL_FROM_NAME` | `Nadcel` |

Ne jamais réutiliser les clés du poste de développement, ni committer
de fichier `.env`.

## 3. Service `rappels` (cron)

**New → GitHub Repo → `nadcel-backend`** (même dépôt), sans domaine public.

- **Variables** : les mêmes que le service `api` (*Shared Variables* ou
  copier-coller).
- **Settings → Deploy → Custom Start Command** :

  ```
  php artisan appointments:send-reminders
  ```

- **Settings → Cron Schedule** :

  ```
  */15 * * * *
  ```

La commande envoie l'e-mail de rappel des rendez-vous confirmés qui ont
lieu dans 24 h (± 15 min), puis s'arrête. Railway la relance toutes les
15 minutes (horaires en UTC, identiques à l'heure de Dakar).

## 4. Premier administrateur

Avec la CLI Railway (`npm i -g @railway/cli`, puis `railway login` et
`railway link`) :

```
railway ssh -s api -- php artisan admin:create admin@exemple.sn "Admin Nadcel"
```

Le mot de passe (16 caractères minimum, majuscule, chiffre, symbole) est
demandé de façon masquée. Aucun compte n'est créé avec un mot de passe connu.

## 5. Site sur Vercel

Projet `nadcel-frontend` → **Settings → Environment Variables** :

| Variable | Valeur |
|---|---|
| `NEXT_PUBLIC_API_URL` | `https://nadcel-api.up.railway.app/api` |

Puis **Redeploy**. Cette variable est lue à la compilation : elle sert aux
appels à l'API et à l'autorisation des photos (`next.config.ts`).

## 6. Vérifications après déploiement

1. `https://<api>/api/health` répond `{"status":"ok"}`.
2. Inscription d'une cliente, connexion, réservation.
3. Demande de partenariat : e-mails reçus (vérifier les courriers indésirables).
4. Photo d'une prestation : elle s'affiche, puis s'affiche encore après un
   redéploiement du service `api` (volume correct).
5. Onglet *Cron Runs* du service `rappels` : une exécution toutes les 15 min.
