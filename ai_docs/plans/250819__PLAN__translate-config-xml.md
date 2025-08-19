Here is a multi-phased implementation plan to create the `topdata:machine-translations:translate-config-xml` command and modernize the existing codebase. This plan is designed to be executed by an AI coding agent.

### **Proposed Command Workflow**

The new command will operate on a single `config.xml` file. Its workflow will be defined by its arguments and options, making it flexible and explicit.

**Command Signature:**
`bin/console topdata:machine-translations:translate-config-xml <file> --from=<lang> --to=<lang> [--force] [--no-backup]`

*   **`file` (argument):** The path to the `config.xml` file that needs translation.
*   **`--from` (option):** The source language code (e.g., `de-DE` or `en-GB`). The command will look for text in nodes with this `lang` attribute. If a node doesn't have language-specific children, it will use the main node's text content as the source, assuming it's in the `--from` language.
*   **`--to` (option, array):** The target language code(s). You can specify this option multiple times to translate into several languages in one run (e.g., `--to=cs-CZ --to=fr-FR`).
*   **`--force` (flag):** If present, the command will overwrite existing translations for the target language. By default, it will skip them.
*   **`--no-backup` (flag):** If present, the command will not create a backup of the original `config.xml` file.

This workflow is robust because it's explicit. It avoids guesswork about the source language and gives the user full control over which languages to add and whether to overwrite existing data.

---

### **Implementation Plan**

#### **Phase 0: Modernization and Preparation**

The goal of this phase is to refactor the existing command to use modern Symfony practices. This sets a consistent standard for the new command.

1.  **Update `Command_TranslateDatabase.php` to use `#[AsCommand]` attribute:**
    *   Open `src/Command/Command_TranslateDatabase.php`.
    *   Add the `use Symfony\Component\Console\Attribute\AsCommand;` statement.
    *   Add the attribute `#[AsCommand(name: 'topdata:machine-translations:translate-database', description: 'Translate content from one language to another')]` directly above the class definition.
    *   Remove the `protected static $defaultName` property.
    *   In the constructor, remove the `parent::__construct(self::$defaultName);` call. The constructor should now only contain `$this->connection = $connection;` and the `parent::__construct()` call.

#### **Phase 1: Create the New Command Skeleton**

This phase focuses on creating the new command file and defining its structure, arguments, and options.

1.  **Create the new command file:**
    *   Create a new file: `src/Command/TranslateConfigXmlCommand.php`.

2.  **Define the command class and signature:**
    *   Inside the new file, create the `TranslateConfigXmlCommand` class, extending `Symfony\Component\Console\Command\Command`.
    *   Use the `#[AsCommand]` attribute to define its name and description:
        ```php
        #[AsCommand(
            name: 'topdata:machine-translations:translate-config-xml',
            description: 'Translates a Shopware config.xml file using DeepL.'
        )]
        ```
    *   Inject the `DeeplTranslator` service through the constructor.

3.  **Configure arguments and options in the `configure()` method:**
    *   Add a required `file` argument: `->addArgument('file', InputArgument::REQUIRED, 'Path to the config.xml file.')`
    *   Add a required `from` option: `->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source language code (e.g., en-GB).')`
    *   Add a required, array-based `to` option: `->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target language code(s) (e.g., de-DE).')`
    *   Add a `force` flag: `->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing translations.')`
    *   Add a `no-backup` flag, consistent with the other command: `->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create a backup of the original file.')`

4.  **Implement the basic `execute()` method:**
    *   Create the `execute(InputInterface $input, OutputInterface $output)` method.
    *   Initialize `CliLogger`.
    *   Retrieve all arguments and options.
    *   Add basic validation: check if the `DEEPL_FREE_API_KEY` is set and if the specified file exists.

#### **Phase 2: Implement XML Parsing and Translation Logic**

This is the core phase where the XML processing and translation happen.

1.  **Implement File Handling:**
    *   In the `execute` method, check if the `--no-backup` flag is set.
    *   If not, create a backup of the input file (e.g., copy `config.xml` to `config.xml.bak`). Inform the user via `CliLogger`.

2.  **Load and Parse the XML:**
    *   Use PHP's `DOMDocument` to load the XML file. It is crucial for preserving the structure and comments.
        ```php
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->load($filePath);
        ```

3.  **Identify Translatable Nodes:**
    *   Use `DOMXPath` to query for all potentially translatable nodes. These are typically `<title>`, `<label>`, `<helpText>`, and `<resetOption>`.
    *   The XPath query would look like: `//title | //label | //helpText | //resetOption`.

4.  **Implement the Translation Loop:**
    *   Iterate through each target language provided in the `--to` option.
    *   Inside that loop, iterate through the `DOMNodeList` returned by the XPath query.
    *   For each node, implement the following logic:
        a.  **Find the source text:**
            *   First, check if a child node with the source language already exists (e.g., a `<label lang="en-GB">`). If so, use its text.
            *   If not, use the text content of the main node itself (`$node->nodeValue`).
            *   If no source text is found, skip to the next node.
        b.  **Check for existing translation:**
            *   Check if a child node for the *target* language already exists.
            *   If it exists and the `--force` flag is NOT set, log a "skipping" message and continue to the next node.
            *   If it exists and `--force` IS set, remove the existing translated node before proceeding.
        c.  **Translate the text:**
            *   Call the `DeeplTranslator->translate()` method with the source text, source language, and target language.
            *   Wrap this call in a `try-catch` block to handle potential API errors gracefully.
        d.  **Create and append the new translated node:**
            *   Create a new element with the same name as the source node (e.g., `$dom->createElement('label', $translatedText)`).
            *   Add the `lang` attribute to this new element with the target language code.
            *   Append the new element as a child of the original node's parent (`$node->parentNode->appendChild($newNode)`).

#### **Phase 3: Finalizing the Command**

This phase ensures the command is robust and user-friendly.

1.  **Save the Modified XML:**
    *   After the loops complete, save the modified `DOMDocument` back to the original file path using `$dom->save($filePath)`.

2.  **Provide User Feedback:**
    *   Throughout the process, use `CliLogger` to provide informative output:
        *   "Backing up file to..."
        *   "Processing translations for language [cs-CZ]..."
        *   "Translating '<label>': 'Show EAN' -> 'Zobrazit EAN'"
        *   "Skipping existing translation for '<title>' in [de-DE]."
    *   At the end, print a summary report (e.g., "Translation complete. Added 12 new translations for cs-CZ. 5 were skipped.").

3.  **Register the new command as a service:**
    *   Open `src/Resources/config/services.xml`.
    *   Add a new service definition for `TranslateConfigXmlCommand`, making sure it is autowired and tagged with `console.command`.
        ```xml
        <service id="Topdata\TopdataMachineTranslationsSW6\Command\TranslateConfigXmlCommand" autowire="true">
            <tag name="console.command"/>
        </service>
        ```

#### **Phase 4: Documentation**

The final phase is to document the new functionality.

1.  **Create a new manual page:**
    *   Create `manual/35-config-translation.en.md` and a German version.
    *   Document the new command, its purpose, and all its arguments/options with clear examples.

2.  **Update the README:**
    *   Add a mention of the new `translate-config-xml` command to `README.md`, perhaps in the "Key Features" or "Quick Start" section.


