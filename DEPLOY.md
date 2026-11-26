# Deployen in Coolify

De repo bevat een `Dockerfile` en `docker-compose.yml` die Coolify direct kan gebruiken
(zelfde opzet als Tamboer Signage).

## Wat er draait

| Service | Rol |
|---|---|
| `app` | nginx + PHP-FPM (serversideup/php 8.4) op poort 8080: het beheer (Filament) op `/admin` en de MCP-server op `/mcp`. Draait bij elke start automatisch de migraties en de Laravel-caches. |
| `worker` | `php artisan queue:work`: alle achtergrondtaken (imports, verrijking, Woo-sync, leveranciersberichten). |
| `scheduler` | `php artisan schedule:work`: dagelijkse voorraadimports, ophalen van leveranciersantwoorden, nachtelijke controles. |
| `mysql` | MySQL 8.4 met een persistent volume. |

Bestanden (leveranciersbestanden, ruwe berichten, afbeeldingen) staan in het volume `storage`,
gedeeld door `app`, `worker` en `scheduler`.

## Stappen

1. **Nieuwe resource** in Coolify: kies project en server, dan *Private Repository (with GitHub App)*
   en selecteer `Big-foot79/Makkelijkpresenteren-Backoffice`, branch `main`.
2. **Build pack**: **Docker Compose**, locatie `/docker-compose.yml`.
3. **Domein**: zet bij service `app` het domein, bijv. `https://backoffice.makkelijkpresenteren.nl`.
   Coolify routeert dat naar poort 8080 via `SERVICE_FQDN_APP_8080`.
4. **Environment variables**: Coolify maakt `SERVICE_USER_MYSQL`, `SERVICE_PASSWORD_MYSQL` en
   `SERVICE_PASSWORD_MYSQLROOT` zelf aan. Zet daarnaast zelf:
   - `APP_KEY`: genereer er een met `php artisan key:generate --show`, of
     `echo "base64:$(openssl rand -base64 32)"`.
5. **Deploy**.

## Eerste keer: gebruiker

Na de eerste deploy, in Coolify bij service `app` via *Terminal*:

```sh
# Beheerder aanmaken (vraagt naam, e-mail en wachtwoord). Het eerste account wordt automatisch Beheerder.
php artisan make:filament-user
```

Inloggen op `https://<jouw-domein>/admin`.

## Leveranciers instellen

De vier leveranciers (TD Synnex, ALSO, Travion, Copaco) worden bij de eerste migratie aangemaakt,
**inactief en zonder inloggegevens**. Per leverancier, onder *Leveranciers → Leveranciers*:

1. Vul server, gebruikersnaam en wachtwoord in. Bij SFTP met sleutel (ALSO) plak je ook de private key
   en de wachtwoordzin; alles wordt versleuteld opgeslagen en nooit in de wijzigingshistorie gezet.
2. Klik **Verbinding testen**: je ziet de bestanden op de server.
3. Klik **Importeren** en kies catalogus en/of voorraad. Volg het resultaat onder *Werk → Runs*.
4. Gaat dat goed, zet de leverancier op **Actief**. Vanaf dan draait de import volgens planning
   (instellingen `cron_*`, Nederlandse tijd). De planning wordt bij het starten van de scheduler
   ingelezen: herstart de service `scheduler` na het wijzigen van een planning of het (de)activeren
   van een leverancier.

Gedownloade bestanden blijven 7 dagen bewaard in het volume `storage` (`imports/<leverancier>/`),
handig om een mislukte import na te kijken.

## AI-agent koppelen (MCP)

Zie [docs/MCP.md](docs/MCP.md). Kort:

```sh
php artisan backoffice:agent "Claude" claude@agents.local
```

Dit maakt een gebruiker met rol *AI-agent* en toont eenmalig een token. De MCP-server staat op
`https://<jouw-domein>/mcp` en verwacht `Authorization: Bearer <token>`.

## Vast IP-adres

TD Synnex en Copaco accepteren bestellingen alleen vanaf vooraf opgegeven IP-adressen. Controleer
na de eerste deploy welk IP de containers naar buiten gebruiken (bijv. `curl https://ifconfig.me`
in de terminal van `worker`) en geef dat door.

## Let op

- HTTPS wordt afgehandeld door Coolify (Traefik); Laravel vertrouwt daarom de proxy-headers.
- De `worker` moet na een deploy herstarten om nieuwe code te laden; Coolify doet dat vanzelf omdat
  alle services opnieuw worden gebouwd.
