### Plan for Integrating PHPStan into the Plugin

This plan details the steps for adding, configuring, and using PHPStan for static analysis within the `TopdataMachineTranslationsSW6` plugin.

#### Phase 1: Installation and Initial Configuration

The goal of this phase is to add PHPStan and its necessary extensions to the project and create a basic configuration file.

1.  **Require Composer Packages:**
    PHPStan is a development dependency. Navigate to the plugin's root directory (`custom/plugins/TopdataMachineTranslationsSW6`) and run the following commands to add PHPStan and extensions that help it understand Shopware, Symfony, and Doctrine:
    ```bash
    # Add PHPStan core
    composer require --dev phpstan/phpstan

    # Add extensions for Doctrine and Symfony
    composer require --dev phpstan/phpstan-doctrine phpstan/phpstan-symfony

    # Add the community-maintained Shopware extension
    composer require --dev sascha-egerer/phpstan-shopware
    ```

2.  **Create the Configuration File:**
    In the root directory of your plugin, create a new file named `phpstan.neon`. This file will contain all the configuration for PHPStan.

3.  **Set Up the Initial `phpstan.neon` Configuration:**
    Add the following content to your `phpstan.neon` file. This sets up the analysis paths, a starting strictness level, and includes the necessary extensions.

    ```neon
    # phpstan.neon
    includes:
        - vendor/phpstan/phpstan-doctrine/extension.neon
        - vendor/phpstan/phpstan-symfony/extension.neon
        - vendor/sascha-egerer/phpstan-shopware/extension.neon

    parameters:
        # The level of strictness, from 0 (most lenient) to 9 (most strict).
        # Start at a low level and increase over time.
        level: 0

        # Paths to analyse.
        paths:
            - src/

        # Path to the Shopware autoloader.
        # This is crucial for PHPStan to find and understand Shopware's classes.
        bootstrapFiles:
            - ../../../../vendor/autoload.php
    ```

#### Phase 2: Generating a Baseline and Achieving Level 0

Instead of fixing all existing errors at once, we will generate a "baseline" file. This tells PHPStan to ignore current errors, allowing you to focus only on new code. The goal is to then work through the baseline until it's empty.

1.  **Generate the Baseline:**
    Run the analysis command with the `--generate-baseline` option. This will create a `phpstan-baseline.neon` file listing all current errors.
    ```bash
    # From the plugin root directory
    ../../../vendor/bin/phpstan analyse --generate-baseline
    ```

2.  **Include the Baseline in Your Configuration:**
    Update your `phpstan.neon` to include the newly generated baseline file. PHPStan will now ignore the errors listed in it.
    ```neon
    # phpstan.neon
    includes:
        - vendor/phpstan/phpstan-doctrine/extension.neon
        - vendor/phpstan/phpstan-symfony/extension.neon
        - vendor/sascha-egerer/phpstan-shopware/extension.neon
        - phpstan-baseline.neon # Add this line

    parameters:
        # ... rest of the config
    ```
    Running `../../../vendor/bin/phpstan analyse` now should result in "No errors".

3.  **Work Towards an Empty Baseline:**
    Incrementally fix the errors listed in `phpstan-baseline.neon`. After fixing a set of errors in your code, regenerate the baseline. It will shrink as you fix more issues. Repeat this process until the baseline file is empty and can be removed from your main configuration. Your code is now "Level 0 Clean".

#### Phase 3: Increasing Strictness and Automation

Once you have a clean slate, you can make the analysis stricter and integrate it into your workflow to prevent future errors.

1.  **Increase the Analysis Level:**
    *   In `phpstan.neon`, change the `level` from `0` to `1`.
    *   Run `phpstan analyse`.
    *   Fix the new errors that are reported.
    *   Repeat this process, gradually increasing the level. A good target for most plugins is Level 5 or 6, which provides a great balance of strictness and practicality.

2.  **Add a Composer Script:**
    Make it easy for any developer to run the analysis by adding a script to your `composer.json`.
    ```json
    "scripts": {
        "analyse": "phpstan analyse"
    }
    ```
    Now you can simply run `composer analyse` from the plugin directory.

3.  **Automate with a CI/CD Pipeline (Recommended):**
    To enforce code quality automatically, add a step to your Continuous Integration pipeline (e.g., GitHub Actions) that runs PHPStan on every pull request. Create a file `.github/workflows/static-analysis.yml` in your plugin's repository.

    ```yaml
    # .github/workflows/static-analysis.yml
    name: Static Analysis

    on: [push, pull_request]

    jobs:
      phpstan:
        name: PHPStan
        runs-on: ubuntu-latest
        steps:
          - name: Checkout
            uses: actions/checkout@v3

          # This step assumes your repository only contains the plugin.
          # If it's part of a larger repo, you will need to adjust paths
          # and ensure Shopware's vendor directory is available.
          - name: Setup PHP
            uses: shivammathur/setup-php@v2
            with:
              php-version: '8.1' # Match your target PHP version
              tools: composer

          - name: Install Dependencies
            run: composer install --prefer-dist --no-progress

          - name: Run PHPStan
            run: composer analyse
    ```

---

### PHPStan Integration Checklist

- [ ] **Phase 1: Installation & Configuration**
    - [ ] Run `composer require --dev` for `phpstan/phpstan`.
    - [ ] Run `composer require --dev` for `phpstan/phpstan-doctrine` and `phpstan/phpstan-symfony`.
    - [ ] Run `composer require --dev` for `sascha-egerer/phpstan-shopware`.
    - [ ] Create `phpstan.neon` in the plugin's root directory.
    - [ ] Configure `paths`, `level: 0`, and `bootstrapFiles` in `phpstan.neon`.
    - [ ] Add the required extensions to the `includes` section of `phpstan.neon`.

- [ ] **Phase 2: Baseline & Initial Cleanup**
    - [ ] Run `phpstan analyse --generate-baseline`.
    - [ ] Include `phpstan-baseline.neon` in the `phpstan.neon` configuration.
    - [ ] Verify that `phpstan analyse` now passes with "No errors".
    - [ ] (Ongoing) Incrementally fix errors and shrink the baseline file.
    - [ ] Once empty, remove the baseline file from `phpstan.neon`.

- [ ] **Phase 3: Strictness & Automation**
    - [ ] (Ongoing) Gradually increase the `level` in `phpstan.neon` and fix new errors.
    - [ ] Add an `analyse` script to `composer.json`.
    - [ ] (Optional but Recommended) Add a GitHub Actions workflow to run PHPStan automatically.

