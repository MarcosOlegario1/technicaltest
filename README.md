# technicaltest

This is a Technical test for Mazza tech and NTT Data, this is only for avaliation and not for commercial consumption in any possitiblity.

It is a Drupal 11 site (Drupal CMS) running on Lando, plus a custom **simple voting
system**: an admin defines questions and answer options, people vote once per
question in the CMS, and an external application can do the same through a
hand-built REST API.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/)
- [Lando](https://docs.lando.dev/getting-started/installation.html) 3.x

## How to run

Clone the repository and, from within the project folder:

```bash
# 1. start the containers (this takes a while the first time as it downloads images)
#    this also creates web/sites/default/settings.php on first run, pointed at
#    Lando's own database — see scripts/lando_create_settings.sh
lando start

# 2. install PHP dependencies
lando composer install

# 3. load the database dump (site + voting module + demo content)
lando db-import db/dump.sql.gz

# 4. rebuild caches
lando drush cr
```

The site is available at **https://my-lando-app.lndo.site**
(or run `lando info` to see all URLs).

Log in as admin:

```bash
lando drush uli
```

### Demo accounts

Created by the database dump. Local use only, trivial passwords.

| User | Password | Can |
| --- | --- | --- |
| `voter` | `voter` | Vote in the CMS |
| `api_consumer` | `api_consumer` | Call the external API |

### Installing from scratch instead

If you prefer a clean install without the dump:

```bash
lando drush site:install drupal_cms_installer -y \
  --db-url=mysql://drupal11:drupal11@database/drupal11 \
  --account-name=admin --account-pass=admin
lando drush en simple_voting simple_voting_api -y
lando drush php:script scripts/seed_demo_content.php
```

## The voting system

Full documentation, data model and API reference:
[`web/modules/custom/simple_voting/README.md`](web/modules/custom/simple_voting/README.md).

- Admin: the **Voting** icon in the admin Content navigation, and
  **Configuration → Content authoring → Simple voting** for the global on/off
  switch.
- CMS voting: **/voting**, or the **Voting question** block on any page.
- API base path: **/api/voting** (JSON, Basic auth). Postman collection at
  [`docs/postman/simple_voting.postman_collection.json`](docs/postman/simple_voting.postman_collection.json).

## Useful commands

```bash
lando drush cr        # clear cache
lando composer <cmd>  # run composer inside the container
lando drush <cmd>     # run drush inside the container
lando db-export       # dump the database
lando stop            # stop the containers
lando rebuild -y      # rebuild (after modifying .lando.yml)
```
