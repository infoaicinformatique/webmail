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
| Dossiers | Arborescence Exchange (Boîte de réception, Envoyés, Brouillons, Indésirables, Supprimés, dossiers personnels) avec le nombre de non-lus. « ＋ Nouveau dossier » sous la liste ; le bouton « ⋯ » d'un dossier propose **Nouveau sous-dossier, Renommer, Déplacer** (dans un autre dossier ou en haut de la boîte) et **Supprimer** (le dossier et son contenu vont dans « Éléments supprimés »). Les dossiers indispensables à Exchange (Boîte de réception, Brouillons, Envoyés, Supprimés, Indésirables) ne peuvent être que complétés de sous-dossiers |
| Espace utilisé | Sous les dossiers : espace occupé par la boîte et limite fixée dans Exchange (« 1,2 Go sur 2 Go (60 %) »), en orange à 75 %, en rouge à 90 % avec un avertissement : au-delà de la limite, Exchange bloque l'envoi. Sans limite connue, seul l'espace occupé est affiché |
| Liste | Défilement continu (50 messages à la fois), aperçu, pièce jointe, importance, suivi (drapeau), actualisation toutes les 2 minutes Des **intertitres** séparent les périodes : « Aujourd'hui », « Hier », « Cette semaine », « La semaine dernière », « Ce mois-ci », puis chaque mois (« Octobre 2026 ») |
| Favoris | « ★ Ajouter aux favoris » dans le menu « ⋯ » d'un dossier l'épingle en haut du volet, sous « Favoris », avec son nombre de non lus : un clic, quelle que soit sa profondeur dans l'arborescence ; on peut y déposer des messages. « ✕ » le retire. Mémorisé par compte dans le navigateur |
| Affichage | Dans « Comptes et signature », la liste des messages peut être **confortable** (avec l'aperçu du texte), **compacte** (sans aperçu) ou sur **une ligne par message** (expéditeur, objet, date côte à côte, sur grand écran) : plus de messages à l'écran. Mémorisé dans le navigateur |
| Trier | Le menu de la liste trie les messages par **date** (récents ou anciens d'abord), par **expéditeur**, par **objet** ou par **taille** (la taille s'affiche alors à la place de la date) ; le tri est fait par Exchange, aussi pour les résultats de recherche, et mémorisé. Les intertitres de période ne s'affichent qu'en tri par date |
| Fluidité | Un dossier déjà ouvert s'affiche **immédiatement**, à la même position, puis se met à jour. Les messages voisins de celui que vous lisez (et celui que survole la souris) sont chargés à l'avance, **sans être marqués comme lus**. Supprimer, déplacer, marquer ou suivre un message est **instantané** (annulé si Exchange refuse) ; après une suppression, le message suivant s'ouvre. L'agenda charge aussi à l'avance la semaine ou le mois précédent et suivant, et les carnets d'adresses déjà vus s'affichent tout de suite. Côté serveur, une seule connexion HTTPS vers Exchange par requête, réponses compressées **Onglets instantanés** : pendant les temps morts de la boîte de réception, l'agenda (la période qui s'ouvre en premier), les tâches à faire et les contacts sont chargés à l'avance, l'un après l'autre ; leur onglet s'affiche alors aussitôt, puis se met à jour (une fois par compte, à nouveau après 10 minutes ; jamais en mode économie de données) Les identifiants des dossiers fixes (boîte de réception, envoyés, agenda, contacts…) sont gardés en cache par Nextcloud : chaque mise à jour de la liste des dossiers, de l'agenda ou des carnets fait **une requête Exchange de moins** Au démarrage, la liste des comptes arrive **avec la page** : les dossiers et les messages sont demandés à Exchange aussitôt, sans requête préalable Les images d'un message (une longue infolettre…) se chargent **au fil du défilement** et sont décodées en arrière-plan : le message s'affiche plus vite et la page reste fluide |
| Clavier | `j`/`k` (ou ↓/↑) message suivant/précédent, `r` répondre, `a` répondre à tous, `f` transférer, `n` nouveau message, `Suppr` supprimer, `u` non lu, `s` suivi, `/` rechercher, `Échap` revenir à la liste, `?` aide (aussi dans « Raccourcis clavier » en bas à gauche) **Palette de commandes** : `Ctrl+K` (`⌘K` sur Mac), depuis n'importe quel onglet, ou « 🔎 Commandes » en bas à gauche : quelques lettres (sans accents) pour aller à un dossier (« fact » → Factures), au courrier, à l'agenda, aux contacts ou aux tâches, créer un message, un événement, un contact ou une tâche, changer de compte, ou rechercher le texte tapé dans tout le courrier ; ↑/↓ puis Entrée |
| Recherche | Recherche Exchange (AQS) dans le dossier courant **ou dans tous les dossiers** (le dossier de chaque résultat est indiqué, 200 résultats au plus). Bouton ⏷ : filtres **Non lus**, **Pièces jointes**, **Suivis** et **période** (7 jours, 30 jours, 12 mois), seuls ou avec des mots Les résultats arrivent **pendant la frappe**, après une courte pause (350 ms, dès 2 caractères ; Entrée cherche tout de suite) ; vider le champ remet le dossier |
| Lecture | Le HTML est nettoyé côté serveur et affiché dans une iframe isolée, sans aucun script. Les images intégrées (`cid:`) s'affichent. Les images distantes sont bloquées tant que l'utilisateur ne clique pas sur « Afficher les images » **Expéditeurs de confiance** : sur un message aux images bloquées, « Toujours pour news@… » ou « Toujours pour @domaine » affiche désormais directement les images de cet expéditeur (ou de tout son domaine), comme Outlook ; la liste se gère dans « Comptes et signature » (✕ pour retirer). Le nom de l'expéditeur pouvant être usurpé, ne l'utilisez que pour des expéditeurs habituels |
| Actions | Marquer comme lu ou non lu, suivi, déplacer, supprimer (vers « Éléments supprimés », puis suppression définitive) **« Annuler »** dans le message de confirmation (8 secondes, tant que la souris est dessus) après un déplacement ou une suppression : les messages reviennent dans leur dossier d'origine (aussi pour plusieurs messages, ou depuis une recherche dans tous les dossiers) |
| Reporter | « ⏰ Reporter » dans la barre d'un message de la boîte de réception : **Plus tard aujourd'hui** (18 h), **Demain matin** (8 h), **Ce week-end** (samedi 9 h), **La semaine prochaine** (lundi 8 h) ou une date et une heure choisies. Le message part dans le dossier « Reportés » (créé la première fois) et **revient dans la boîte de réception, non lu**, à l'heure dite, même webmail fermé (tâche de fond de Nextcloud, toutes les 5 minutes). Dans « Reportés », chaque message affiche quand il revient, avec « Remettre dans la boîte de réception maintenant » |
| Suivi avec échéance | « ▾ » à côté de « ⚑ Suivi » : **Aujourd'hui**, **Demain**, **Cette semaine** (vendredi), **La semaine prochaine**, sans date ou une date choisie, et « Retirer le suivi ». L'échéance s'affiche dans la liste (« Demain », « En retard » en rouge) ; c'est l'indicateur d'Exchange, le même que dans Outlook (liste « Tâches » / To-Do) et sur le téléphone |
| Courrier indésirable | « ⛔ Indésirable » déplace le message dans « Courrier indésirable » et **bloque l'expéditeur** (ses prochains messages y iront directement) ; dans ce dossier, « ✔ Pas indésirable » le remet dans la boîte de réception et **fait confiance à l'expéditeur**. Comme dans Outlook, les listes d'expéditeurs bloqués et fiables sont celles d'Exchange. Aussi pour plusieurs messages à la fois |
| Se désabonner | Sur un message d'une **liste de diffusion** (en-tête `List-Unsubscribe`), un bandeau propose « Se désabonner » : en **un clic** quand la liste le permet (RFC 8058, la demande part du serveur Nextcloud), sinon par un **message de désinscription envoyé** de la boîte (après confirmation), ou en ouvrant la page de désinscription de la liste. Les autres messages de la même liste l'indiquent ensuite |
| En-têtes Internet | « 🧾 » dans la barre d'un message : les contrôles du serveur de réception (**SPF**, **DKIM**, **DMARC** : réussi, échoué…) avec un **avertissement** si l'expéditeur n'a pas pu être authentifié (usurpation possible), le **trajet** du message de serveur en serveur avec le temps de chaque étape, puis tous les en-têtes et « Copier les en-têtes » pour le support ; comme « Propriétés du message » d'Outlook |
| Plusieurs messages | Case à cocher devant chaque message (au survol), **Ctrl+clic** pour en ajouter, **Maj+clic** pour une plage, `x` au clavier ; une barre propose alors Tout sélectionner, Lu, Non lu, Suivi, Déplacer vers… et Supprimer, pour tous d'un coup. Échap annule la sélection. **Glisser-déposer** un message (ou la sélection) sur un dossier le déplace |
| Envoi | Nouveau message, Répondre, Répondre à tous, Transférer (Exchange cite le message d'origine et garde ses pièces jointes), Cc/Cci, importance, signature ; `Ctrl+Entrée` envoie **Réponse rapide** : sous chaque message, « Répondre à … » : quelques lignes, `Ctrl+Entrée` ou « Envoyer » (signature, délai d'annulation et alerte de pièce jointe oubliée compris), « Répondre à tous » s'il y a d'autres destinataires, et « Plus d'options… » pour continuer dans le formulaire complet avec le texte déjà tapé |
| Transférer en pièce jointe | « 📎 » à côté de « Transférer » joint le message entier (fichier .eml, avec ses propres pièces jointes) à un nouveau message ; « ↪ Transférer » dans la barre de sélection fait de même pour plusieurs messages (20 au plus) : un seul envoi, chaque message en pièce jointe, que Outlook ou Thunderbird ouvrent comme un message |
| Tâche depuis un message | « ✅ » dans la barre d'un message ouvre une **nouvelle tâche** Exchange : titre repris de l'objet (sans « RE: »), notes avec l'expéditeur, la date et le texte du message ; il reste à choisir l'échéance. Pour ne pas oublier de traiter un message, comme « Assurer un suivi » d'Outlook mais en vraie tâche |
| Événement depuis un message | « 📅 » dans la barre d'un message ouvre un **nouvel événement** de l'agenda : titre repris de l'objet (sans « RE: »), description avec le texte du message, expéditeur et destinataires (sauf soi) proposés comme participants ; comme « Répondre par une réunion » d'Outlook |
| Ajouter l'expéditeur | « 👤＋ » à côté de l'expéditeur d'un message l'ajoute à « Contacts » (prénom et nom tirés du nom affiché) ; s'il y est déjà avec cette adresse, rien n'est créé en double |
| Liens « mailto: » | Une adresse e-mail cliquée dans un message reçu ouvre un **nouveau message** dans le webmail (destinataires, copie, objet et texte du lien repris). « Comptes et signature » → « Ouvrir les liens « mailto: » avec ce webmail » en fait le programme de messagerie du navigateur : les adresses cliquées sur les autres sites arrivent ici (`…/apps/webmail/?mailto=…`) |
| Modèles de réponse | Le bouton 📄 de l'éditeur insère un **modèle** là où se trouve le curseur, ou enregistre le texte écrit comme modèle (nom au choix ; la signature n'en fait pas partie). Les modèles appartiennent à l'utilisateur Nextcloud (tous ses comptes) et se renomment ou se suppriment dans « Comptes et signature » ; ils sont nettoyés comme une signature (images comprises, 256 Ko et 50 modèles au plus) ([capture](docs/capture-modeles.png)) |
| Options d'envoi | « Demander un accusé de lecture », et « **Envoyer plus tard** » à la date et l'heure choisies (demain 8 h proposé) : Exchange garde le message dans « Boîte d'envoi » jusque-là, même si Nextcloud est fermé. « 🖨 » imprime un message (en-têtes et contenu) ; « ⬇ » le télécharge en **.eml** (ouvert par Outlook, Thunderbird, Apple Mail…, pièces jointes comprises) et « ☁️ » l'enregistre en .eml dans Fichiers. Dans l'autre sens, « Tout marquer comme lu » du menu d'un dossier (s'il a des non-lus) le fait en une seule requête Exchange, sans accusé de lecture, quel que soit le nombre de messages. « Importer des messages (.eml)… » du même menu (ou des fichiers .eml déposés sur le dossier) y range des messages venant d'Outlook, de Thunderbird ou d'une autre boîte, comme des messages reçus et lus. **Annuler l'envoi** : après « Envoyer », le message attend quelques secondes (5 par défaut, réglable dans « Comptes et signature » : aucun, 5, 10, 20 ou 30 s) avec « Annuler l'envoi », qui le rouvre tel quel, et « Envoyer maintenant » ; fermer la page pendant ce délai demande confirmation ([capture](docs/capture-annuler-envoi.png)). **Pièce jointe oubliée** : si le texte parle d'une pièce jointe (« ci-joint », « en PJ », « pièce jointe », « attached »…, hors signature et citation) et qu'aucun fichier n'est joint, l'envoi demande confirmation |
| Éditeur | Texte **mis en forme** : gras, italique, souligné, listes à puces et numérotées, liens, citations ; les adresses web tapées deviennent des liens. Le contenu est nettoyé sur le serveur avant l'envoi (aucun script ne peut passer) |
| Images dans le texte | Une **capture d'écran collée** (Ctrl+V), une image **glissée** dans le texte ou ajoutée avec « 🖼 » s'insère à l'endroit du curseur. Les photos de plus de 1600 pixels sont réduites (JPEG), les captures restent nettes (PNG). À l'envoi, chaque image devient une **image intégrée** (`cid:`), comme avec Outlook : elle s'affiche chez tous les destinataires sans apparaître en pièce jointe, et une même image collée deux fois n'est jointe qu'une fois. Les images comptent dans la limite de 25 Mo |
| Signature | Dans « Comptes et signature », une signature **mise en forme** pour chaque compte : gras, italique, souligné, liens, et un **logo** (image ajoutée avec « 🖼 » ou collée, réduite à 400 pixels). Elle s'ajoute sous chaque nouveau message et chaque réponse, le logo étant envoyé en image intégrée. Une signature écrite avant en texte simple est reprise telle quelle |
| Brouillons | Le message en cours est **enregistré automatiquement** dans « Brouillons » quelques secondes après chaque modification (« Brouillon enregistré à 15:42 ») et en fermant avec « Annuler ». Dans « Brouillons », un clic **rouvre le brouillon** pour le terminer et l'envoyer (y compris une réponse ou un transfert, avec sa citation et ses pièces jointes). « Supprimer le brouillon » le jette. Les **pièces jointes d'un nouveau message** (ordinateur ou Fichiers) sont enregistrées avec le brouillon dès qu'on les ajoute : rien n'est perdu en fermant la fenêtre, et « retirer » les enlève du brouillon. Celles d'une réponse ou d'un transfert, et les fichiers lourds (15 Mo et plus, qu'on peut encore envoyer en lien), partent au moment de l'envoi |
| Pièces jointes | Téléchargement (une par une ou **toutes dans un .zip** nommé d'après l'objet, un message joint en .eml), **enregistrement dans Fichiers** (une par une ou **toutes d'un coup**), ajout depuis l'ordinateur ou **depuis Fichiers** (25 Mo au total) |
| Aperçu | Miniatures des photos ; **aperçu plein écran** des images, des PDF et des fichiers texte (← / → pour passer de l'une à l'autre, Échap pour fermer). Le texte est affiché tel quel, rien n'y est interprété ; les autres types (Word, zip…) se téléchargent |
| Destinataires | Complétion automatique des adresses à partir des contacts Exchange **et de l'annuaire de l'entreprise** Les **destinataires récents** (ceux à qui vous avez écrit ou répondu depuis ce navigateur, les plus fréquents d'abord) sont proposés **en premier, dès la première lettre**, sans attendre Exchange (« · récent ») |
| Invitations | Une invitation reçue par e-mail affiche la date et le lieu, avec les boutons **Accepter / Provisoire / Refuser** |
| Réponse automatique | « 🌴 Réponse automatique » (dans « Comptes et signature ») : comme dans Outlook, activée tout de suite ou **pendant une période**, avec un message pour vos collègues et, au choix, aucune réponse, une réponse à vos contacts extérieurs ou à tout le monde à l'extérieur (même message ou un autre). Tant qu'elle est active, un rappel avec « Modifier » et « Désactiver » s'affiche au-dessus des dossiers |
| Liens Nextcloud | « 🔗 Lien Nextcloud » dans la rédaction : un fichier ou un dossier de Fichiers devient un **lien de téléchargement** dans le texte du message (nom, taille, date de fin si l'administrateur en impose une), au lieu d'une pièce jointe. Un fichier déjà joint depuis Fichiers passe en lien avec « 🔗 en lien ». Un fichier lourd de l'ordinateur (15 Mo et plus) est signalé, et « 🔗 Envoyer en lien » le dépose dans Fichiers (dossier « Pièces jointes envoyées ») puis met son lien dans le message. Un lien en lecture seule déjà existant est réutilisé |
| Boîtes partagées | Dans « Comptes et signature », « 📮 Boîtes partagées » : ajoutez une boîte commune (contact@, accueil@…) dont l'administrateur Exchange vous a donné l'**accès total**. Ses dossiers s'affichent sous les vôtres (avec les non-lus) : lecture, réponse, déplacement entre ses dossiers, suppression. En répondant depuis la boîte partagée, « De : » propose cette boîte (il faut le droit « **Envoyer en tant que** ») ; la liste « De : » permet aussi d'écrire un nouveau message en son nom. Retirer une boîte du webmail ne supprime rien dans Exchange |
| Boîtes des collègues (délégation) | Si un collègue vous a fait **délégué** de sa boîte de réception dans Outlook (Fichier → Paramètres du compte → Accès délégué), ajoutez son adresse au même endroit que les boîtes partagées. Elle s'affiche avec 👥, avec **seulement les dossiers qu'il vous a partagés** (sa boîte de réception et ses sous-dossiers, par exemple). On peut lire, classer et **répondre de sa part** : « De : » propose son adresse, et le message part « de la part de » lui. Mention « (délégué) » dans « Comptes et signature » |
| Conversations | Sous l'en-tête d'un message : « ▸ Conversation : 3 messages » quand il fait partie d'un échange. Tous les messages de la conversation, **dans tous les dossiers** (vos réponses dans « Éléments envoyés » comprises, sauf brouillons et éléments supprimés), du plus ancien au plus récent, avec l'expéditeur, la date, le dossier et le début du texte. Un clic déplie un message sur place (avec « Ouvrir ce message » et « Répondre ») ; la conversation reste dépliée ou repliée d'un message à l'autre |
| Ignorer une conversation | « 🔇 » dans un message : comme dans Outlook, la conversation part dans « Éléments supprimés » et ses **prochains messages y vont directement** (règle tenue par Exchange, aussi pour Outlook et le téléphone). « Annuler » la remet dans la boîte de réception |
| Liste par conversation | Le bouton « 🗨 » en haut de la liste **regroupe les messages par conversation**, comme « Afficher en tant que conversations » d'Outlook : une ligne par échange, avec ses expéditeurs, la date du dernier message et le **nombre de messages** (en couleur s'il y a des non-lus). Un clic ouvre le message le plus récent, avec toute la conversation dessous. Le choix est retenu ; une recherche affiche toujours les messages un par un. Les cases à cocher et le glisser-déposer restent disponibles dans l'affichage message par message |
| Catégories | Les **catégories Outlook** s'affichent en pastilles de couleur dans la liste et sur le message (mêmes noms et couleurs qu'Outlook, lus dans la boîte). Bouton « 🏷 » sur un message, ou « 🏷 Catégoriser » pour plusieurs messages cochés : cocher / décocher une catégorie, ou en **créer une** avec sa couleur (25 couleurs d'Outlook), ajoutée à la liste de la boîte. Un clic sur une pastille du message affiche tous les messages de cette catégorie, dans tous les dossiers |
| Règles | « 📋 Règles » (dans « Comptes et signature ») ou « 📋 Règle… » sur un message (son expéditeur est déjà rempli) : les règles qu'Exchange applique à chaque message reçu, **même webmail fermé**. Conditions : expéditeur, mots dans l'expéditeur, l'objet ou le texte, destinataire, pièce jointe, envoyé à moi seul, importance. Actions : déplacer, copier, mettre à la corbeille, marquer comme lu, importance, catégorie, transférer, ne pas appliquer les règles suivantes. Activer / désactiver, changer l'ordre, supprimer. Les règles faites dans Outlook avec d'autres réglages sont affichées (« créée dans Outlook ») et peuvent être activées, désactivées, déplacées ou supprimées sans être abîmées. Comme dans Outlook sur le web, la première modification remplace la copie des règles gardée par Outlook pour Windows : ses règles « côté client uniquement » (qui ne fonctionnent qu'Outlook ouvert) sont alors à refaire |
| Délégation | Si vous êtes **délégué** d'un collègue dans Outlook, Exchange vous transmet une copie de ses invitations : le bandeau indique « Invitation reçue pour Marie Martin (vous êtes son délégué) » et la réponse est envoyée **en son nom** (après confirmation) |
| Nouveaux messages | Le nombre de non-lus de la boîte de réception s'affiche dans le **titre de l'onglet** (« (3) Webmail »). Avec « 🔔 Activer les notifications » (en bas à gauche), chaque nouveau message de chaque compte déclenche une **notification du navigateur** quand la fenêtre n'est pas au premier plan (un clic ouvre le message), ou un message dans la page sinon. Vérification chaque minute, tant que Nextcloud est ouvert dans un onglet |
| Notifications Nextcloud | Même **webmail fermé**, chaque nouveau message de la boîte de réception apparaît dans les **notifications Nextcloud** (la cloche, et le téléphone avec l'application Nextcloud) : « Nouveau message de … », l'objet et le début du texte. Un clic ouvre le message ; ouvert, lu, déplacé ou supprimé dans le webmail, il quitte la cloche. Au plus 5 par compte à chaque vérification, rien pour les messages déjà là au premier passage. Case « 🔔 Signaler chaque nouveau message… » dans « Comptes et signature » (cochée par défaut). Vérification toutes les 5 minutes par les tâches de fond de Nextcloud : réglez-les sur **Cron** (Paramètres d'administration → Paramètres de base) |
| Rappels des rendez-vous | Le rappel de chaque rendez-vous de l'agenda Exchange (celui réglé dans Outlook ou le webmail : « 15 minutes avant »…) arrive dans les **notifications Nextcloud** au bon moment, même webmail fermé : « Rappel : Comité de pilotage », l'heure, le lieu. Un clic ouvre l'agenda sur le rendez-vous ; la notification disparaît quand il est terminé. Une notification par occurrence d'un rendez-vous périodique. Case « ⏰ Rappels des rendez-vous… » dans « Comptes et signature » (cochée par défaut). Vérification chaque minute par les tâches de fond de Nextcloud (réglage **Cron** conseillé) |
| Recherche unifiée | La loupe de Nextcloud (depuis n'importe quelle app) cherche aussi dans Exchange : « **Courrier Exchange** » (tous les dossiers de tous les comptes, les plus récents d'abord, avec l'expéditeur et la date) et « **Agenda Exchange** » (six mois avant et après, les prochains rendez-vous d'abord). Un clic ouvre le message, ou l'agenda à la bonne date avec l'événement ouvert. À partir de 3 lettres |
| Mobile | Affichage adapté aux téléphones ; le bouton **Retour** du téléphone ferme le message (ou le menu) au lieu de quitter le webmail **Gestes** : un message **balayé vers la gauche** part dans « Éléments supprimés » (avec « Annuler »), **vers la droite** il passe en lu / non lu ; la couleur et le libellé apparaissent pendant le geste, l'action ne se fait qu'au-delà d'un tiers de la largeur |

![Raccourcis clavier](docs/capture-raccourcis.png)

![Éditeur et brouillons](docs/capture-editeur.png)

![Images dans le texte](docs/capture-images-collees.png)

![Signature mise en forme](docs/capture-signature.png)

![Pièces jointes enregistrées avec le brouillon](docs/capture-pj-brouillon.png)

![Réponse automatique](docs/capture-absence.png)

![Règles](docs/capture-regles.png)

![Catégories](docs/capture-categories.png)

![Conversation](docs/capture-conversation.png)

![Liste par conversation](docs/capture-liste-conversations.png)

![Boîte partagée](docs/capture-boite-partagee.png)

![Boîte d'un collègue dont on est délégué](docs/capture-boite-deleguee.png)

![Lien Nextcloud](docs/capture-lien-partage.png)

![Notifications Nextcloud](docs/capture-cloche.png)

![Recherche unifiée](docs/capture-recherche-unifiee.png)

![Rappel d'un rendez-vous](docs/capture-rappel.png)

![Plusieurs messages](docs/capture-selection.png)

![Dossiers](docs/capture-dossiers.png)

![Aperçu des pièces jointes](docs/capture-apercu.png)

![Recherche avec filtres](docs/capture-recherche.png)

Les messages envoyés sont enregistrés par Exchange dans « Éléments envoyés ». Ils apparaissent donc aussi dans Outlook et sur les téléphones.

### Agenda

![Agenda](docs/capture-agenda.png)

![Vue semaine](docs/capture-semaine.png)

![Vue jour](docs/capture-jour.png)

![Recherche d'événements](docs/capture-recherche-agenda.png)

![Le 2e mardi du mois](docs/capture-2e-mardi.png)

![Visio Talk](docs/capture-talk.png)

![Disponibilités](docs/capture-disponibilites.png)

![Salles de réunion](docs/capture-salles.png)

![Agenda des dossiers publics](docs/capture-agenda-public.png)

| | |
|---|---|
| Vues | **Jour**, **semaine**, **mois** et **liste** des 30 prochains jours. Mini-calendrier à gauche : un point sous les jours qui ont des rendez-vous (dans la période affichée), ses propres flèches pour parcourir les mois sans changer l'affichage principal, un clic va au jour, un double-clic l'affiche seul (vue jour). Les événements périodiques sont dépliés |
| Recherche | Champ « Rechercher un événement » : dans l'objet, le lieu et l'organisateur, sans tenir compte des accents ni des majuscules, de six mois avant à six mois après aujourd'hui, dans tous les agendas affichés (les événements périodiques apparaissent à chaque date). Un clic ouvre l'événement |
| Semaine | Grille horaire sur 7 jours. Une ligne « Journée » regroupe les événements sur la journée entière. Les événements qui se chevauchent sont placés côte à côte, et un trait rouge marque l'heure actuelle. Un clic sur un créneau libre crée un événement à cette heure. Un clic sur un jour du mini-calendrier affiche sa semaine **Glisser pour créer** : appuyer sur une heure libre et tirer jusqu'à l'heure de fin dessine le créneau (par quarts d'heure), puis ouvre le formulaire avec ce début et cette fin ; un simple clic ouvre toujours une demi-heure |
| Détail | Horaire, lieu, organisateur, participants et leur réponse, description |
| Création / modification | Titre, journée entière, début et fin, lieu, participants et facultatifs (avec complétion), rappel, disponibilité |
| Répétition | Chaque jour, semaine, mois ou année, **tous les N** jours, semaines ou mois, **plusieurs jours de la semaine** (par ex. lundi et jeudi). Fin : jamais, à une date ou après N occurrences. Chaque mois ou chaque année **à une date** (« le 12 ») **ou un jour de la semaine** (« le 2e mardi », « le dernier vendredi », « le 4e jeudi de novembre »), proposés d'après la date du premier événement. Un résumé en clair s'affiche, par ex. « Toutes les 2 semaines le lundi et le jeudi, 10 fois » |
| Plusieurs agendas | **Vos autres agendas** Exchange (par ex. « Congés équipe ») apparaissent automatiquement. Chaque agenda a sa couleur et une case pour l'afficher ou le masquer. À la création d'un événement, on choisit l'agenda |
| Agendas partagés | « Ajouter un agenda partagé » : saisissez l'adresse d'un collègue (avec complétion). Le niveau d'accès est **détecté automatiquement** : **modification** ou **lecture** si l'agenda vous a été partagé dans Outlook, sinon **disponibilités** seulement (le partage par défaut d'Exchange : créneaux occupés, hachurés, avec l'objet s'il est partagé). En lecture, rien n'est modifiable ; en modification, on peut créer, modifier, déplacer et supprimer. La liste est propre à chaque utilisateur et à chaque compte ; retirer un agenda ne supprime rien dans Exchange |
| Partager mon agenda | Bouton 🔗 à côté de chacun de vos agendas : choisissez ce que **toute l'organisation** et **chaque personne** peuvent voir ou faire (aucun accès, disponibilités seulement, disponibilités avec objet et lieu, lecture, lecture et ajout, modification), **sans en faire un délégué**. C'est le réglage « Autorisations » d'Outlook ; les niveaux personnalisés créés dans Outlook sont affichés et conservés tels quels. Vos collègues ajoutent ensuite votre agenda avec « Ajouter un agenda partagé » |
| Glisser-déposer | À la souris, dans la vue **semaine**, glisser un événement le déplace à une autre heure ou un autre jour, par pas de 15 minutes. Tirer son **bord inférieur** change l'heure de fin. Dans la vue **mois** et la ligne « Journée », glisser un événement le déplace sur un autre jour en gardant ses horaires. **Échap** annule. Pour une réunion, une confirmation est demandée et les participants reçoivent la mise à jour. Seuls les événements dont vous êtes l'organisateur peuvent être déplacés. Sur écran tactile, un appui ouvre l'événement, et on le modifie avec « Modifier » |
| Séries | Depuis une occurrence : **« Modifier cette occurrence »** ou **« Modifier la série »**. Les deux modifient titre, horaires, lieu, participants, rappel et disponibilité ; seule la série permet de changer la règle de répétition ou de la supprimer (la série devient alors un événement unique). Les participants reçoivent la mise à jour. Pour une règle créée dans Outlook que l'application ne sait pas modifier (par ex. « le 2e jour ouvré du mois »), la règle est conservée et seuls les horaires sont modifiables |
| Réunions | Les participants reçoivent l'invitation, puis les mises à jour et les annulations (envoyées par Exchange) |
| Dossiers publics | « ＋ Ajouter un agenda des dossiers publics » : parcourez les dossiers publics Exchange et ajoutez un agenda commun (planning de l'équipe, réservations, fermetures…). Il s'affiche avec vos agendas ; lecture ou modification selon les droits donnés par l'administrateur Exchange (création, modification, déplacement, suppression d'événements — définitive, les dossiers publics n'ayant pas d'« Éléments supprimés »). Retirer l'agenda de la liste ne supprime rien |
| Disponibilités | « 📅 Disponibilités » dans le formulaire : une ligne par participant (et vous), sur la journée de 7 h à 20 h : occupé, provisoire, absent, et la réunion telle qu'elle est placée ; ‹ › pour changer de jour. « **Premier créneau libre pour tous** » place la réunion (même durée) au premier moment où tout le monde est libre, jours ouvrés de 8 h à 18 h, dans les deux semaines. Les personnes extérieures sont signalées « disponibilités inconnues » et ne bloquent pas la recherche |
| Salles de réunion | « 🚪 Choisir une salle » dans le formulaire : les **listes de salles** d'Exchange (« Salles Paris », « Salles Lyon »…, la dernière choisie est retenue) et, pour chaque salle, si elle est **libre** ou **occupée** au moment de la réunion (les libres d'abord). « Réserver » ajoute la salle à l'événement (et son nom dans le lieu s'il est vide) ; à l'enregistrement, Exchange lui envoie la demande et la salle **accepte ou refuse** d'elle-même. Le détail de l'événement indique « réservée », « refusée » ou « en attente de confirmation ». La salle apparaît aussi dans les disponibilités. ✕ retire une salle. Les listes de salles sont créées par l'administrateur Exchange |
| Visio | **Matrix / Element** : si l'administrateur a indiqué son serveur **Element Call** (Paramètres d'administration → Webmail Exchange → « Visio des réunions », par ex. `https://call.element.io`), « 🎥 Visio Element » dans le formulaire ajoute une **salle chiffrée propre à la réunion** (`…/room/#/point-de-chantier-k3x9?password=…`, le mot de passe après « # » n'est jamais envoyé au serveur) : son lien va dans le lieu et dans la description, donc dans l'invitation. Une autre visio (Jitsi…) se règle avec une adresse contenant `{room}` (`https://meet.example.org/{room}`). Sans réglage, et avec **Nextcloud Talk** installé, « 🎥 Visio Talk » crée une salle publique Talk. Dans le détail d'un événement, « 🎥 Rejoindre la visio » ouvre la salle (ou le lien Teams, Zoom… donné comme lieu) |
| Invitations reçues | Accepter, provisoire ou refuser depuis l'agenda ou depuis l'e-mail |
| Réponse au nom d'un collègue | Dans l'agenda d'un collègue partagé en **modification** (délégué ou éditeur), ses invitations affichent « Réponse de Marie Martin » et les boutons pour répondre **en son nom**, après confirmation. Impossible en lecture seule |
| Suppression | D'un événement, d'une occurrence ou de toute la série |
| Fuseau horaire | Celui du navigateur, transmis à Exchange (Europe, DOM-TOM et principales zones) |

![Agendas partagés](docs/capture-agendas-partages.png)

![Partager mon agenda](docs/capture-partage-agenda.png)

### Contacts

![Contacts](docs/capture-contacts.png)

![Groupe de contacts](docs/capture-groupe-contacts.png)

![Photo d'un contact](docs/capture-photo-contact.png)

![Import vCard](docs/capture-vcard.png)

![Contacts partagés](docs/capture-contacts-partages.png)

![Partager mes contacts](docs/capture-partage-contacts.png)

![Ajouter les carnets d'un collègue](docs/capture-carnets-collegue.png)

![Dossiers publics](docs/capture-dossiers-publics.png)

| | |
|---|---|
| Carnets | **Vos carnets d'adresses** Exchange (« Contacts » et les autres, par ex. « Fournisseurs »), les **contacts partagés** par vos collègues, et l'**annuaire de l'entreprise** |
| Liste | Contacts du carnet choisi, triés et groupés par initiale, avec recherche |
| Contacts partagés | « Ajouter les contacts d'un collègue » : saisissez son adresse. Nextcloud cherche les carnets qu'il vous partage : son dossier « Contacts » et **ses autres carnets** (par ex. « Clients VIP »). S'il en partage plusieurs, cochez ceux à ajouter ; vous pouvez revenir plus tard en ajouter d'autres. L'accès, **lecture** ou **modification**, est détecté pour chaque carnet. Le collègue partage un carnet dans Outlook (clic droit → Propriétés → Autorisations) ; un carnet autre que « Contacts » n'est trouvé que s'il est rangé dans son « Contacts » partagé, ou si sa boîte vous donne le droit « Dossier visible ». En lecture seule, un bouton « Ajouter à mes contacts » copie la fiche dans votre carnet. Retirer un carnet de la liste ne supprime rien dans Exchange |
| Partager mes contacts | Bouton 🔗 à côté de chacun de vos carnets : choisissez ce que **toute l'organisation** et **chaque personne** peuvent faire (aucun accès, lecture, lecture et ajout de contacts, modification), **sans en faire un délégué**. C'est le réglage « Autorisations » du dossier dans Outlook ; les niveaux personnalisés créés dans Outlook sont affichés et conservés tels quels. Vos collègues ajoutent ensuite votre carnet avec « Ajouter les contacts d'un collègue » ; pour un carnet autre que « Contacts », rangez-le dans « Contacts » ou donnez-leur aussi le droit « Dossier visible » sur votre boîte |
| Dossiers publics | « Ajouter un carnet des dossiers publics » : parcourez les dossiers publics Exchange comme dans Outlook et ajoutez les carnets d'adresses qui s'y trouvent. L'accès, **lecture** ou **modification**, est celui défini par l'administrateur Exchange ; une suppression y est définitive et vaut pour tout le monde. La recherche porte sur le nom, la société et l'e-mail (Exchange n'indexe pas les dossiers publics). Le webmail trouve la boîte des dossiers publics par Autodiscover (Exchange 2013 et suivants) ; si votre offre Exchange n'a pas de dossiers publics, la fenêtre l'indique |
| Annuaire de l'entreprise | Toutes les personnes et listes de diffusion de l'organisation Exchange, avec service, fonction, téléphone et site. Exchange ne permet que la **recherche**, à partir de 2 lettres et dans la limite de 100 résultats. Lecture seule, avec « Écrire » et « Ajouter à mes contacts » |
| Fiche | Société, fonction, jusqu'à 3 e-mails, mobile, téléphone du bureau et du domicile, **adresse Matrix** (`@prenom.nom:serveur`, ou un lien matrix.to collé ; « 💬 Écrire sur Matrix » ouvre la discussion dans Element ou l'application Matrix de la personne ; gardée dans la 1re adresse de messagerie instantanée d'Exchange, donc aussi dans Outlook, et en `IMPP:matrix:` dans les vCard), adresse (lien vers la carte), notes |
| Actions | Créer (dans le carnet choisi), modifier, supprimer, **écrire** (ouvre un nouveau message), appeler (lien `tel:`) |
| Import / export | « ⬇ » au-dessus de la liste : tout le carnet affiché dans un fichier **vCard** (.vcf) pour Outlook, un téléphone, Nextcloud Contacts… « ⬆ » : les contacts d'un fichier vCard (2.1, 3.0 ou 4.0, celui d'Outlook ou d'un téléphone) sont ajoutés au carnet ; ceux déjà présents (même adresse e-mail) sont ignorés, et le bilan s'affiche |
| Groupes | Les **groupes de contacts** d'Outlook (listes de distribution personnelles) : leurs membres s'affichent, « ✉️ Écrire au groupe » adresse le message à tous. « 👥 Nouveau groupe » en crée un, « ✏️ Modifier » ajoute des membres (complétion depuis les contacts et l'annuaire, ou adresse saisie) ou en retire. Les groupes sont enregistrés dans Exchange et retrouvés dans Outlook |
| Photos | La **photo** d'un contact (celle mise dans Outlook) s'affiche dans la liste et sur sa fiche ; « 📷 Choisir une photo » dans « Modifier » en met une (recadrée en carré, réduite à 256 pixels), « Retirer la photo » l'enlève. Dans l'annuaire de l'entreprise, chaque collègue apparaît avec la **photo de son compte Exchange**. Sans photo, ses initiales sur une couleur |

### Tâches

![Tâches](docs/capture-taches.png)

L'onglet **✅ Tâches** affiche le dossier « Tâches » de la boîte Exchange, le même que dans Outlook et Outlook sur le web.

| | |
|---|---|
| Liste | « À faire » : les tâches en retard d'abord (échéance en rouge, « En retard · 10 nov. »), puis par échéance (« Aujourd'hui », « Demain »…), puis celles sans échéance ; importance haute marquée ❗, état « En cours » / « En attente » / « Reportée » affiché. « Terminées » : les tâches finies, les plus récentes d'abord |
| Ajout rapide | Champ « Ajouter une tâche » en haut de la liste : un titre, Entrée, et la tâche est créée |
| Terminer | La case devant une tâche la marque **terminée** (100 %) ou la remet à faire, tout de suite |
| Depuis un message | « ✅ » dans la barre d'un message : une nouvelle tâche, titre et notes repris du message |
| Modifier | Un clic ouvre la tâche : titre, **échéance** (raccourcis Aujourd'hui, Demain, Dans une semaine, Aucune), état, importance, notes. « Supprimer » la met dans « Éléments supprimés » |

### Délégués

![Délégués](docs/capture-delegues.png)

« Comptes et signature » → **👥 Délégués** : les mêmes réglages que l'« Accès délégué » d'Outlook, pour chaque boîte.

| | |
|---|---|
| Liste | Vos délégués actuels, avec leurs droits |
| Droits | Pour l'**agenda**, la **boîte de réception**, les **contacts** et les **tâches** : aucun accès, lecture, lecture et création, ou modification |
| Invitations | « Reçoit une copie de mes invitations et peut y répondre en mon nom » (nécessite l'agenda en modification) ; « Peut voir mes éléments privés » |
| Envoi des invitations | Vos invitations vont à vos délégués et à vous, à vos délégués seulement avec une copie pour information, ou à vos délégués seulement |
| Ajout / retrait | Ajout par adresse (avec complétion), modification des droits, retrait (les droits sont alors retirés par Exchange) |

Côté délégué, l'agenda et les contacts partagés s'ajoutent dans l'onglet correspondant. Les invitations reçues en délégation permettent de répondre au nom de la personne.

### Tableau de bord Nextcloud

Deux widgets pour le tableau de bord de Nextcloud (bouton « Personnaliser » en bas du tableau de bord) :

| | |
|---|---|
| Courrier non lu | Les derniers messages non lus des boîtes de réception de tous vos comptes Exchange (expéditeur, objet ; le compte s'il y en a plusieurs). Un clic ouvre le message dans le webmail |
| Agenda Exchange | Les prochains rendez-vous des 7 prochains jours de vos agendas Exchange, avec l'heure (« Aujourd'hui 14:00 », « Demain, toute la journée ») et le lieu. Un clic ouvre l'agenda |

Les deux sont actualisés toutes les quelques minutes ; Exchange n'est interrogé qu'une fois par minute au plus. Un administrateur peut les proposer à tout le monde par défaut : `occ config:app:set dashboard layout --value="webmail-unread,webmail-agenda,…"`.

![Tableau de bord](docs/capture-tableau-de-bord.png)

## Prérequis

- Nextcloud 28 à 34, PHP 8.1 à 8.5, avec les extensions `curl` et `dom`
- Le serveur Nextcloud doit pouvoir joindre `https://exN.mail.ovh.net` (port 443 sortant)
- Aucune dépendance Composer ni étape de compilation n'est nécessaire à l'exécution

## Installation

```bash
make dist
```

```bash
sudo tar -xzf build/webmail-1.97.0.tar.gz -C /var/www/nextcloud/custom_apps/
```

```bash
sudo chown -R www-data:www-data /var/www/nextcloud/custom_apps/webmail
```

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable webmail
```

Vous pouvez aussi copier les dossiers `appinfo lib templates js css img` et les
fichiers `l10n/*.js` et `l10n/*.json` dans `custom_apps/webmail`. L'archive de
`make dist` contient exactement cela, traductions comprises (avant la 1.59, elle
oubliait le dossier `l10n` : l'app restait en français pour tout le monde).

### Publication sur la boutique d'apps Nextcloud

`appinfo/info.xml` suit le schéma de la boutique (résumé et description en anglais
et en français, dépôt, captures). Pour publier une version :

1. Obtenir un certificat pour l'identifiant `webmail` (une seule fois, voir la
   documentation « App Store » de Nextcloud) et garder la clé privée hors du dépôt.
2. Construire l'archive et sa signature :

```bash
make appstore APP_KEY=~/.nextcloud/certificates/webmail.key
```

3. Publier l'archive à une adresse publique (par exemple une « release » GitHub),
   puis la déclarer sur apps.nextcloud.com avec le contenu de `build/webmail-<version>.tar.gz.sig`.

Les captures déclarées dans `info.xml` sont lues sur GitHub : le dépôt doit être
public au moment de la publication.

### Mise à jour

Remplacez le dossier `custom_apps/webmail` par la nouvelle version, puis lancez :

```bash
sudo -u www-data php /var/www/nextcloud/occ upgrade
```

Les comptes déjà configurés sont conservés. Les versions 1.1 à 1.97 ne modifient pas la base de données (les agendas et contacts partagés ajoutés sont enregistrés dans les préférences de l'utilisateur).

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

### Découverte automatique (Autodiscover)

![Découverte automatique](docs/capture-autodiscover.png)

Pour les domaines absents du tableau, cochez « **Chercher automatiquement le serveur des autres domaines** ». Quand un utilisateur ajoute une adresse d'un autre domaine, le webmail cherche son serveur comme Outlook : `autodiscover.<domaine>`, puis `<domaine>`, avec l'adresse et le mot de passe saisis. Le serveur trouvé est **retenu pour ce domaine** et affiché sous le tableau (« Oublier » pour le chercher à nouveau).

Par sécurité :

- seuls les serveurs dont le nom se termine par l'un des « **Serveurs autorisés** » sont acceptés (`mail.ovh.net` par défaut : `ex3.mail.ovh.net`, `ex5.mail.ovh.net`…) ;
- aucune adresse du réseau local n'est contactée, sauf si `config.php` contient `'allow_local_remote_servers' => true`, comme pour les autres connexions de Nextcloud.

La découverte est désactivée par défaut. Les domaines du tableau et le domaine `*` restent prioritaires.

La même configuration peut se faire en ligne de commande :

```bash
sudo -u www-data php occ config:app:set webmail servers --value='[{"domain":"societe-a.fr","label":"OVH ex3","url":"ex3.mail.ovh.net"},{"domain":"societe-b.fr","label":"OVH ex5","url":"ex5.mail.ovh.net"}]'
```

### Réseau, proxy et certificats

Les requêtes vers Exchange suivent les réglages réseau de Nextcloud, comme ses autres connexions sortantes :

- le **proxy** de `config.php` (`proxy`, `proxyuserpwd`, et `proxyexclude` pour les hôtes à joindre en direct) ;
- les **certificats** de Nextcloud, y compris ceux importés par l'administrateur (`occ security:certificates:import`), par exemple pour un proxy qui inspecte le HTTPS.

### Tâches de fond et performances

- Réglez les tâches de fond de Nextcloud sur **Cron** : les notifications de nouveaux messages et le retour des messages reportés (toutes les 5 minutes), et les rappels des rendez-vous (chaque minute) en dépendent.
- Une exécution encore en cours n'est pas relancée à côté d'elle. Chaque requête faite en tâche de fond abandonne au bout de 20 secondes, pour qu'un serveur injoignable ne retarde pas les autres comptes.
- Les nouveaux messages passent par un **abonnement Exchange** à la boîte de réception (« pull subscription ») : Exchange garde les nouveaux messages entre deux vérifications, et une vérification sans nouveau message ne coûte qu'une petite requête. Si Exchange a oublié l'abonnement (plus d'une heure sans vérification), le webmail se réabonne et rattrape par les dates, sans annoncer deux fois un message.
- Les rappels ne demandent les prochains rendez-vous à Exchange qu'une fois toutes les 5 minutes par compte. Le calcul se refait chaque minute sans requête, et immédiatement après une modification de l'agenda dans le webmail.
- Un cache partagé est recommandé, par exemple **Redis** dans `memcache.distributed`. Le tableau de bord, les rappels, l'espace utilisé et la boîte des dossiers publics y sont gardés. APCu seul ne sert pas aux tâches de fond, qui tournent chacune dans un nouveau processus. Sans cache, tout fonctionne, avec plus de requêtes vers Exchange.
- **Dans le navigateur**, ce qui a déjà été vu s'affiche aussitôt puis se met à jour : les listes des dossiers, les 100 derniers messages ouverts, le message survolé et les deux suivants pendant la lecture (préchargés sans être marqués lus). Les listes des 6 derniers dossiers vus et les dossiers de chaque compte sont aussi gardés **pour l'onglet** (`sessionStorage`, vidé à sa fermeture) : après un rechargement, la boîte s'affiche avant la réponse d'Exchange. Seuls les résumés y sont gardés (expéditeur, objet, date, aperçu), jamais le contenu des messages ; un autre utilisateur Nextcloud dans le même onglet ne voit rien du précédent, et retirer un compte efface ce qui le concerne. Les lignes hors écran des longues listes ne sont pas dessinées (`content-visibility`), ce qui garde le défilement fluide.
- Un dossier jamais vu affiche d'abord ses 25 premiers messages, puis le reste de la page juste après. Quand le navigateur est inactif, les 5 premiers non-lus de la boîte de réception sont préchargés (sans être marqués lus) : le premier clic les ouvre aussitôt. Ce préchargement est désactivé si l'onglet est caché ou si la connexion demande d'économiser les données.

## Utilisation

1. Ouvrez l'application **Webmail** dans la barre du haut.
2. Saisissez l'adresse Exchange et son mot de passe. La connexion est testée avant l'enregistrement.
3. Passez du courrier à l'agenda, aux contacts ou aux tâches avec les onglets en haut à gauche. L'application se rouvre sur le dernier onglet utilisé.
4. Pour la signature, une autre boîte ou un changement de mot de passe, utilisez **Comptes et signature**
   en bas à gauche.

## Sécurité

- Les mots de passe Exchange sont chiffrés avec la clé de l'instance Nextcloud (`ICrypto`).
  Un utilisateur ne voit que ses propres comptes. Les comptes sont supprimés en même temps que l'utilisateur Nextcloud.
- Les utilisateurs ne choisissent pas l'URL du serveur : seuls les serveurs définis par l'administrateur sont contactés, et uniquement en HTTPS.
- Le corps des messages est nettoyé (scripts, formulaires, objets, gestionnaires `on*`, URL `javascript:`, CSS dangereux,
  commentaires HTML — qu'un navigateur ne termine pas toujours au même endroit que le serveur)
  puis affiché dans une iframe `sandbox` sans `allow-scripts`. La politique CSP de Nextcloud bloque en plus les scripts en ligne.
  Les images distantes restent bloquées sous toutes leurs formes (`src`, `srcset`, CSS `url()` même échappé, `image-set()`).
- Les photos de contacts sont servies avec le type de l'image elle-même (JPEG, PNG ou GIF), jamais celui déclaré dans
  Exchange, et avec `X-Content-Type-Options: nosniff`.
- Autodiscover se connecte uniquement aux adresses publiques vérifiées juste avant (une seconde réponse DNS ne peut pas
  rediriger la requête vers le réseau local).
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
  (corps et en-têtes) avec le même schéma. L'étape Autodiscover suppose une seconde instance du serveur factice sur le port 443 et,
  dans le conteneur Nextcloud, `autodiscover.societe-c.fr` dirigé vers elle (`/etc/hosts`).
- Intégration continue (`.github/workflows/tests.yml`, à chaque pull request et sur `main`) : tests unitaires
  sur PHP 8.1 à 8.5, syntaxe des scripts, traductions générées à jour, `info.xml` validé avec le schéma de la
  boutique d'apps. Le scénario e2e, qui demande un Nextcloud et le serveur EWS factice, se lance à la main.
- Versions testées : le scénario complet passe sur **Nextcloud 31** (PHP 8.3) et **Nextcloud 34** (PHP 8.5),
  et les tests unitaires sur PHP 8.3 et 8.5. Pour viser une autre instance : `NC_URL=http://localhost:8081
  OCC='docker exec -u www-data nc34 php occ' node tests/e2e/run-e2e.js`.

Traductions : l'app est écrite en français ; chaque texte de la page passe par `_('…')` (la fonction `t` de Nextcloud), et côté serveur par `IL10N::t()` (messages d'erreur `MailException('… %s', [$param])`, notifications, widgets, page d'administration), le texte français servant de clé (le fichier `fr` est vide, mais nécessaire : sans lui, Nextcloud chargerait l'anglais pour un compte en français). Les traductions sont dans `l10n/source/<langue>.json` (texte français → traduction) ; `php l10n/build.php` produit les fichiers que Nextcloud charge (`l10n/<langue>.js` et `.json`, `en` et `en_GB` pour l'anglais). La langue suivie est celle du compte Nextcloud (Paramètres personnels → Langue) :

![Le courrier en anglais](docs/capture-anglais.png)

Le test `L10nTest` vérifie que chaque texte a sa traduction et qu'il ne reste pas de texte français hors de `_()` dans les fichiers traduits.

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

Toutes les évolutions envisagées sont réalisées, jusqu'à la traduction anglaise (1.56) et à la fluidité de l'affichage : mémoire d'onglet (1.57), préchargement des non-lus et première page plus courte (1.58).
