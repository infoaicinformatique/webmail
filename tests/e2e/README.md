# Tests de bout en bout

1. Démarrer le serveur EWS factice (HTTPS + Basic, mot de passe `secret`) :

   ```bash
   openssl req -x509 -newkey rsa:2048 -nodes -keyout key.pem -out cert.pem -days 2 -subj "/CN=ex3.fake.test" -addext "subjectAltName=DNS:ex3.fake.test,DNS:ex5.fake.test"
   python3 tests/e2e/fake_ews.py --port 8443 --cert cert.pem --key key.pem --log /tmp/ews-log
   ```

2. Nextcloud de test (Docker) avec l'app copiée dans `custom_apps/webmail`, `firstrunwizard` désactivé,
   puis :

   ```bash
   occ app:enable webmail
   occ config:app:set webmail verify_tls --value=no
   occ config:app:set webmail servers --value='[{"domain":"societe-a.fr","url":"ex3.fake.test:8443"},{"domain":"societe-b.fr","url":"ex5.fake.test:8443"}]'
   ```

   (lancer le conteneur avec `--add-host=ex3.fake.test:host-gateway --add-host=ex5.fake.test:host-gateway`)

3. `NC_URL=http://localhost:8080 NC_PASS=... node tests/e2e/run-e2e.js`
4. `php tests/e2e/validate_logged_requests.php /tmp/ews-log` : vérifie chaque requête envoyée avec le schéma EWS officiel.

Entre deux exécutions, remettez l'état à zéro : relancez `fake_ews.py` (ses données sont en mémoire),
videz `oc_webmail_accounts`, et videz aussi `oc_ratelimit_entries`. Sans cela, la limite de 10 ajouts de compte
par 5 minutes bloque le scénario après quelques passages.
Lancez Playwright avec le fuseau `Europe/Paris` (déjà configuré dans `run-e2e.js`).
