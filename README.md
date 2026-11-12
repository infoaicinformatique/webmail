# Webmail Exchange pour Nextcloud

Application Nextcloud qui affiche le **courrier, l'agenda et les contacts** des boîtes **OVH Hosted Exchange**
directement dans Nextcloud.
Elle passe par **Exchange Web Services (EWS)**, l'API native d'Exchange, et non par IMAP/SMTP.

- Plusieurs serveurs Exchange, **un par domaine** : l'administrateur associe par exemple
  `societe-a.fr` à `ex3.mail.ovh.net` et `societe-b.fr` à `ex5.mail.ovh.net`.
- L'utilisateur saisit seulement **son adresse et son mot de passe**. Le serveur est déduit du domaine.
- Un utilisateur peut connecter plusieurs boîtes, y compris sur des serveurs différents.

![Lecture d'un message](docs/capture-lecture.png)

## Fonctionnalités

Trois onglets en haut à gauche : **Courrier**, **Agenda**, **Contacts**. Tout est lu et écrit en direct sur
Exchange, sans copie ni synchronisation : une modification faite dans Nextcloud apparaît aussitôt dans Outlook
et sur les téléphones, et inversement.

### Courrier

| | |
|---|---|
| Dossiers | Arborescence Exchange (Boîte de réception, Envoyés, Brouillons, Indésirables, Supprimés, dossiers personnels) avec le nombre de non-lus |
| Liste | Pagination (50 par page), aperçu, pièce jointe, importance, suivi (drapeau), actualisation toutes les 2 minutes |
| Recherche | Recherche Exchange (AQS) dans le dossier courant |
| Lecture | Le HTML est nettoyé côté serveur et affiché dans une iframe isolée, sans aucun script. Les images intégrées (`cid:`) s'affichent. Les images distantes sont bloquées tant que l'utilisateur ne clique pas sur « Afficher les images » |
| Actions | Marquer comme lu ou non lu, suivi, déplacer, supprimer (vers « Éléments supprimés », puis suppression définitive) |
| Envoi | Nouveau message, Répondre, Répondre à tous, Transférer (Exchange cite le message d'origine et garde ses pièces jointes), Cc/Cci, importance, signature |
| Pièces jointes | Téléchargement, **enregistrement dans Fichiers**, ajout depuis l'ordinateur ou **depuis Fichiers** (25 Mo au total) |
| Destinataires | Complétion automatique des adresses à partir des contacts Exchange **et de l'annuaire de l'entreprise** |
| Invitations | Une invitation reçue par e-mail affiche la date et le lieu, avec les boutons **Accepter / Provisoire / Refuser** |
| Mobile | Affichage adapté aux téléphones |

Les messages envoyés sont enregistrés par Exchange dans « Éléments envoyés ». Ils apparaissent donc aussi dans Outlook et sur les téléphones.

### Agenda

![Agenda](docs/capture-agenda.png)

| | |
|---|---|
| Vues | Mois (avec mini-calendrier) et liste des 30 prochains jours. Les événements périodiques sont dépliés |
| Détail | Horaire, lieu, organisateur, participants et leur réponse, description |
| Création / modification | Titre, journée entière, début et fin, lieu, participants et facultatifs (avec complétion), rappel, disponibilité, répétition (chaque jour, semaine, mois ou année, avec date de fin facultative) |
| Réunions | Les participants reçoivent l'invitation, puis les mises à jour et les annulations (envoyées par Exchange) |
| Invitations reçues | Accepter, provisoire ou refuser depuis l'agenda ou depuis l'e-mail |
| Suppression | D'un événement, d'une occurrence ou de toute la série |
| Fuseau horaire | Celui du navigateur, transmis à Exchange (Europe, DOM-TOM et principales zones) |

### Contacts

![Contacts](docs/capture-contacts.png)

| | |
|---|---|
| Liste | Contacts personnels de la boîte, triés et groupés par initiale, avec recherche |
| Fiche | Société, fonction, jusqu'à 3 e-mails, mobile, téléphone du bureau et du domicile, adresse (lien vers la carte), notes |
| Actions | Créer, modifier, supprimer, **écrire** (ouvre un nouveau message), appeler (lien `tel:`) |
| Groupes | Les listes de distribution sont affichées, leur gestion reste dans Outlook |

## Prérequis

- Nextcloud 28 à 32, PHP 8.1 ou plus récent, avec les extensions `curl` et `dom`
- Le serveur Nextcloud doit pouvoir joindre `https://exN.mail.ovh.net` (port 443 sortant)
- Aucune dépendance Composer ni étape de compilation n'est nécessaire à l'exécution

## Installation

```bash
make dist
```

```bash
sudo tar -xzf build/webmail-1.1.0.tar.gz -C /var/www/nextcloud/custom_apps/
```

```bash
sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/webmail
```

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable webmail
```

Vous pouvez aussi copier les dossiers `appinfo lib templates js css img` dans
`custom_apps/webmail`.

### Mise à jour

Remplacez le dossier `custom_apps/webmail` par la nouvelle version, puis lancez :

```bash
sudo -u www-data php /var/www/nextcloud/occ upgrade
```

Les comptes déjà configurés sont conservés. La version 1.1 ne modifie pas la base de données.

## Configuration (administrateur)

**Paramètres d'administration → Webmail Exchange**, puis une ligne par domaine :

| Domaine | Libellé | Serveur Exchange |
|---|---|---|
| `societe-a.fr` | OVH ex3 | `ex3.mail.ovh.net` |
| `societe-b.fr` | OVH ex5 | `ex5.mail.ovh.net` |

- Le nom du serveur figure dans l'espace client OVH, rubrique **Hosted Exchange → Informations générales**
  (champ « Serveur »). Il suffit de saisir le nom d'hôte : l'URL complète
  `https://exN.mail.ovh.net/EWS/Exchange.asmx` est ajoutée à l'enregistrement.
- Le domaine `*` sert de serveur par défaut pour tous les autres domaines.

La même configuration peut se faire en ligne de commande :

```bash
sudo -u www-data php occ config:app:set webmail servers --value='[{"domain":"societe-a.fr","label":"OVH ex3","url":"ex3.mail.ovh.net"},{"domain":"societe-b.fr","label":"OVH ex5","url":"ex5.mail.ovh.net"}]'
```

## Utilisation

1. Ouvrez l'application **Webmail** dans la barre du haut.
2. Saisissez l'adresse Exchange et son mot de passe. La connexion est testée avant l'enregistrement.
3. Passez du courrier à l'agenda ou aux contacts avec les onglets en haut à gauche. L'application se rouvre sur le dernier onglet utilisé.
4. Pour la signature, une autre boîte ou un changement de mot de passe, utilisez **Comptes et signature**
   en bas à gauche.

## Sécurité

- Les mots de passe Exchange sont chiffrés avec la clé de l'instance Nextcloud (`ICrypto`).
  Un utilisateur ne voit que ses propres comptes. Les comptes sont supprimés en même temps que l'utilisateur Nextcloud.
- Les utilisateurs ne choisissent pas l'URL du serveur : seuls les serveurs définis par l'administrateur sont contactés, et uniquement en HTTPS.
- Le corps des messages est nettoyé (scripts, formulaires, objets, gestionnaires `on*`, URL `javascript:`, CSS dangereux)
  puis affiché dans une iframe `sandbox` sans `allow-scripts`. La politique CSP de Nextcloud bloque en plus les scripts en ligne.
- L'ajout de compte est limité à 10 tentatives par 5 minutes et par utilisateur.
- Le certificat TLS du serveur Exchange est vérifié. Pour un environnement de test uniquement, cette vérification se désactive avec
  `occ config:app:set webmail verify_tls --value=no`.

## Dépannage

| Message | Cause probable |
|---|---|
| « Aucun serveur Exchange n'est configuré pour le domaine … » | Le domaine n'est pas déclaré dans l'administration |
| « Identifiant ou mot de passe Exchange incorrect » | Mauvais mot de passe, ou compte bloqué chez OVH |
| « Accès EWS refusé … » | EWS désactivé pour ce compte (à vérifier dans l'espace client OVH) |
| « Impossible de joindre le serveur Exchange » | Pare-feu ou DNS : testez `curl -I https://exN.mail.ovh.net/EWS/Exchange.asmx` depuis le serveur Nextcloud (une réponse 401 est normale) |

Les erreurs détaillées sont écrites dans le journal Nextcloud (`app: webmail`).

## Développement et tests

```bash
make test
```

- `tests/unit` : tests PHPUnit. **Chaque requête EWS produite est validée par le schéma XSD officiel
  d'Exchange 2013 SP1** (`tests/fixtures/xsd`), la version annoncée par le client.
- `tests/e2e` : serveur EWS factice (`fake_ews.py`, HTTPS et authentification Basic) et scénario Playwright
  (`run-e2e.js`), avec agenda, contacts, invitations et annuaire factices (`fake_ews_pim.py`). Le scénario couvre, dans un vrai
  Nextcloud, le courrier (connexion, liste, lecture, pièces jointes, recherche, envoi, réponse, transfert, déplacement, suppression,
  signature, deux serveurs), les invitations, la complétion d'adresses, l'agenda (vues, détail, modification, création avec
  invitation, répétition, suppression de série, réponse), les contacts (recherche, fiche, modification, création, suppression)
  et l'affichage mobile. `validate_logged_requests.php` vérifie ensuite chaque requête reçue par le serveur factice
  (corps et en-têtes) avec le même schéma.

Structure :

```
appinfo/            info.xml, routes.php
lib/Ews/            EwsClient (client SOAP EWS sans dépendance, curl + DOM),
                    EwsCalendar, EwsContacts, TimeZones (fuseaux IANA → Windows)
lib/Service/        comptes, correspondance domaine/serveur, nettoyage HTML
lib/Controller/     API JSON utilisée par l'interface
js/, css/           interface en JavaScript natif, sans compilation
                    (webmail.js = courrier + socle, calendar.js, contacts.js)
templates/          page principale et page d'administration
```

## Évolutions possibles

- Autres agendas (secondaires ou partagés) et dossiers de contacts autres que les principaux
- Vue semaine dans l'agenda, modification d'une série périodique entière
- Brouillons modifiables et éditeur HTML
- Découverte automatique (Autodiscover) à la place de la table domaine/serveur
