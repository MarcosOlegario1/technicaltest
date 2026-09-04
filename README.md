# technicaltest

This is a Technical test for Mazza tech and NTT Data, this is only for avaliation and not for commercial consumption in any possitiblity.
## Prerequisites

- [Docker](https://docs.docker.com/get-docker/)
- [Lando](https://docs.lando.dev/getting-started/installation.html) 3.x

## How to run

Clone the repository and, from within the project folder:

```bash
# 1. start the containers (this takes a while the first time as it downloads images)
lando start

# 2. install PHP dependencies
lando composer install

# 3. install Drupal
lando drush site:install drupal_cms_installer -y \
  --db-url=mysql://drupal11:drupal11@database/drupal11 \
  --account-name=admin --account-pass=admin
```

You're all set. The site is available at **https://my-lando-app.lndo.site**
(or run `lando info` to see all URLs).

To log in as admin, generate a login link:

```bash
lando drush uli
```

## Useful commands thaat you may need

```bash
lando drush cr        # clear cache
lando composer <cmd>  # run composer inside the container
lando drush <cmd>     # run drush inside the container
lando stop            # stop the containers
lando rebuild -y      # rebuild (after modifying .lando.yml)
```