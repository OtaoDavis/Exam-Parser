# Laravel Exam Parser with AI Answers

This project allows users to upload exam documents (PDF, DOCX) or images (JPG, PNG). It uses Laravel Queues to process these files in the background. For documents, text is extracted. This text (or a note for images, pending OCR implementation) is sent to the Google Gemini API. The API extracts metadata (subject, class, year, etc.) and *generates answers* to the questions found in the exam content. The metadata is saved to a database, and the generated answers are saved to a text file in public storage. The web interface displays a list of processed exams and provides download links for the generated answer files.

## Features

* File Upload (PDF, DOCX, PNG, JPG)
* Background Job Processing using Laravel Queues
* Text Extraction from PDF (using `smalot/pdfparser`) and DOCX (using `phpoffice/phpword`)
* Metadata Extraction & Answer Generation via Google Gemini API
* Storage of Metadata in Database (`exams` table)
* Storage of Generated Answers as Text Files (`storage/app/public/exam_answers/`)
* Web Interface to Upload Files and Download Generated Answers
* Basic UI Styling with Tailwind CSS

## Requirements

* PHP >= 8.1 (Check your Laravel version's requirements)
* Composer
* Node.js & NPM (for frontend dependencies, if any, and Tailwind compilation if not using CDN)
* Database (MySQL, PostgreSQL, SQLite, etc. supported by Laravel)
* Google Gemini API Key
* Queue Driver configured (Database, Redis, SQS, etc.) - Database driver used in examples.

## Setup Instructions

1.  **Clone the Repository:**
    ```bash
    git clone <your-repository-url>
    cd <repository-directory>
    ```

2.  **Install PHP Dependencies:**
    ```bash
    composer install
    ```

3.  **Install Node Dependencies (Optional - if you have frontend assets):**
    ```bash
    npm install
    npm run dev # Or 'npm run build' for production
    ```
    *(If you are only using the Tailwind CDN as in the example view, you can skip this step)*

4.  **Environment Setup:**
    * Copy the example environment file:
        ```bash
        cp .env.example .env
        ```
    * Generate an application key:
        ```bash
        php artisan key:generate
        ```
    * Edit the `.env` file and configure the following:
        * `APP_NAME`, `APP_ENV`, `APP_URL`
        * **Database Connection:** `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
        * **Queue Connection:** Set `QUEUE_CONNECTION=database` (or your preferred driver like `redis`).
        * **Gemini API Key:** Add your Google Gemini API Key:
            ```dotenv
            GEMINI_API_KEY=your_google_gemini_api_key_here
            ```

5.  **Database Migration:**
    * Set up the database queue table (if using `database` driver):
        ```bash
        php artisan queue:table
        ```
    * Run the database migrations to create the `exams` table (and `jobs`, `failed_jobs` tables):
        ```bash
        php artisan migrate
        ```

6.  **Storage Link:**
    * Create a symbolic link from `public/storage` to `storage/app/public`. This makes files stored in `storage/app/public` (like images and answer files) publicly accessible.
    ```bash
    php artisan storage:link
    ```
    * Ensure your web server has the necessary permissions to write to the `storage` directory (especially `storage/app/public/exam_images` and `storage/app/public/exam_answers`).

7.  **Configure Queue Worker:**
    * For local development, run the queue worker in your terminal:
        ```bash
        php artisan queue:work
        ```
        *(Keep this running to process jobs)*
    * For production, you **must** use a process manager like Supervisor to keep the `queue:work` process running reliably in the background. Configure Supervisor to run `php artisan queue:work --sleep=3 --tries=3` (adjust parameters as needed). Refer to Laravel documentation for Supervisor configuration.

8.  **Web Server Configuration:**
    * Ensure your web server (Nginx, Apache) document root is set to the `/public` directory of your Laravel project.
    * Configure necessary rewrite rules (usually handled by default Laravel `.htaccess` or Nginx config examples).

## How it Works

1.  **Upload:** The user uploads a file via the web form (`/exams`).
2.  **Dispatch:** The `ParsingController@store` method validates the file, stores it temporarily (images go to `public/exam_images`, docs to `storage/app/uploads`), and dispatches the `ProcessExamFile` job to the queue.
3.  **Queue:** The Laravel queue worker picks up the job.
4.  **Job Execution (`ProcessExamFile@handle`):**
    * Extracts text content from PDF/DOCX files. (Image OCR is currently a placeholder).
    * Constructs a prompt containing the extracted text.
    * Calls the Google Gemini API, requesting metadata extraction and answer generation in JSON format.
    * Parses the JSON response from the API.
    * Extracts the generated answers string.
    * Saves the generated answers to a `.pdf` file in `storage/app/public/exam_answers/`.
    * Creates a new record in the `exams` database table, storing the extracted metadata and the *filename* of the generated answers file.
    * Deletes the temporary uploaded PDF/DOCX file from `storage/app/uploads`.
5.  **Display:** The `ParsingController@create` method fetches processed exams from the database and displays them in the view (`parser.blade.php`). If an exam record has an answers filename, a download link pointing to the public URL of the answers file is generated.

## Important Notes
* **API Costs:** Be mindful of the costs associated with using the Google Gemini API, especially with large documents or frequent use.
* **Error Handling:** The job includes basic error handling and logging. Check `storage/logs/laravel.log` for details on processing errors or API issues. Failed jobs will be logged in the `failed_jobs` table if using the database queue driver.
* **Queue Monitoring:** In production, monitor your queue worker and the `failed_jobs` table.
* **Security:** Ensure proper file validation and sanitization are in place. Be cautious about the content generated by the AI.
* **Scalability:** For high volume, consider using a more robust queue driver (like Redis or SQS) and scaling your queue workers.
