# Upgrade guide

## Upgrading from 1.x to 2.0

Clearance 2.0 ships no CSS. The panel uses the fonts, colours and dark mode of your application, compiled by your own Tailwind CSS v4 build (Flux must be configured in the host, with the `@import` of `flux.css`). See the "Styling" section of the [README](README.md#styling).

1. Update the package: `composer require rivalex/clearance:^2.0`.
2. Add this line to `resources/css/app.css`, next to your Flux import, and rebuild the assets (`npm run build`):

   ```css
   @source '../../vendor/rivalex/clearance/resources/views';
   ```

   On an installed host `php artisan clearance:install` stops at its marker (`storage/.clearance-installed`) and does not add the line unless you run it with `--force`, so add it by hand. Without the line the panel is unstyled.
3. Remove the `dark_mode` key from a published `config/clearance.php`. The setting is gone: dark mode is the host's, and `CLEARANCE_FORCE_DARK_MODE` has no effect.
4. Optional: delete the leftover `dark_mode.force` row of the removed Appearance card. It is inert. The table is `clr_settings` and the column is `key` (a reserved word in MySQL and SQL Server, so quote it):

   ```sql
   DELETE FROM clr_settings WHERE `key` = 'dark_mode.force';  -- MySQL
   DELETE FROM clr_settings WHERE "key" = 'dark_mode.force';  -- PostgreSQL, SQLite
   DELETE FROM clr_settings WHERE [key] = 'dark_mode.force';  -- SQL Server
   ```
5. Published views, if you published any, must drop `<x-clearance::dark-scope>`, the stylesheet link and `<livewire:clearance::settings.appearance-settings />`. These no longer exist.

The panel now follows the host's fonts, colours and dark mode. The product logos are unchanged.
