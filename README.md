# Factbook backend deployment

The pull-changes workflow connects over SSH, updates the server's main branch with a fast-forward pull, installs locked production Composer dependencies, checks PHP extensions, runs migrations and rebuilds Laravel configuration and route caches. It runs on pushes to main and can be started manually from the Actions tab on main. Deployments run one at a time.

## GitHub repository secrets

Configure these in the factbook-backend repository under Settings, Secrets and variables, Actions.

| Secret | Value |
| --- | --- |
| PROD_HOST | Server SSH hostname or IP address |
| PROD_USER | Server SSH username |
| PROD_SSH_KEY | Private SSH key authorized for that server account |
| PROD_REMOTE_PATH | Absolute path to the backend Git checkout containing artisan and composer.json |
| PROD_PORT | Optional SSH port, default 22 |
| PHP_BIN | Optional PHP CLI executable or absolute path, default php. The locked dependencies currently require PHP 8.4. |

The runner trusts the server with ssh-keyscan, following the reference workflow's convention.

## Server setup

Clone the Factbook backend repository on the server and configure its origin so the server account can fetch it without an interactive prompt. The workflow uses that existing origin, including a configured GitHub SSH host alias when applicable. The Actions key authenticates the runner to the server. The server's GitHub credentials authenticate its Git pull.

Configure the application's production environment and database credentials on the server. Serve the backend public directory as the web document root. Grant the PHP process write access to bootstrap/cache and storage. The workflow creates the Laravel runtime directories required by a fresh checkout.

Install Composer on the server as a PHP executable on PATH or place composer.phar in the backend root. The workflow invokes Composer and Artisan through PHP_BIN, so a configured hosting PHP binary applies to both.

The Git update uses checkout main followed by pull --ff-only. Git reports conflicting server edits or divergent history as deployment failures. Migration and dependency failures stop the workflow.

The initial DPWH snapshot import and verification are separate setup steps using factbook:import and factbook:verify. Subsequent deploys run schema migrations. The API health endpoint is /up.

## Verification

Workflow structure, embedded Bash and deployment-command behavior are checked locally with mocked SSH, Git, Composer and Artisan commands. An actual GitHub Actions run and server deployment still require the configured secrets and server checkout.

[GitHub Actions concurrency documentation](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency)


