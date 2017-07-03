<?php
/**
 * Terminus Plugin that contain a collection of commands useful during
 * the build step on a [Pantheon](https://www.pantheon.io) site that uses
 * a GitHub PR workflow.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusBuildTools\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessUtils;
use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Semver\Comparator;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\SiteProviders\SiteEnvironment;

use Robo\Contract\BuilderAwareInterface;
use Robo\LoadAllTasks;

/**
 * Build Tool Base Command
 */
class BuildToolsBase extends TerminusCommand implements SiteAwareInterface, BuilderAwareInterface
{
    use LoadAllTasks; // uses TaskAccessor, which uses BuilderAwareTrait
    use SiteAwareTrait;

    const TRANSIENT_CI_DELETE_PATTERN = '^ci-';
    const PR_BRANCH_DELETE_PATTERN = '^pr-';
    const DEFAULT_DELETE_PATTERN = self::TRANSIENT_CI_DELETE_PATTERN;

    protected $tmpDirs = [];

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Terminus requires php 5.5, so we know we have at least that version
     * if we get this far.  Warn the user if they are using php 5.5, though,
     * as we recommend php 5.6 or later (e.g. for Drupal 8.3.0.)
     */
    protected function warnAboutOldPhp()
    {
        if (Comparator::lessThan(PHP_VERSION, '5.6.0')) {
            $this->log()->warning('You are using php {version}; it is strongly recommended that you use at least php 5.6. Note that older versions of php will not work with newer template projects (e.g. Drupal 8.3.0).', ['version' => PHP_VERSION]);
        }
    }

    /**
     * Get the email address of the logged-in user
     */
    protected function loggedInUserEmail()
    {
        if (!$this->session()->isActive()) {
            $this->log()->notice('No active session.');
            return;
        }

        $user_data = $this->session()->getUser()->fetch()->serialize();
        if (!array_key_exists('email', $user_data)) {
            $this->log()->notice('No email address in active session data.');
            return;
        }

        // Look up the email address of the active user (as auth:whoami does).
        return $user_data['email'];
    }

    /**
     * Recover the session's machine token.
     */
    protected function recoverSessionMachineToken()
    {
        $email_address = $this->loggedInUserEmail();
        if (!$email_address) {
            return;
        }

        // Try to look up the machine token using the Terminus API.
        $tokens = $this->session()->getTokens();
        $token = $tokens->get($email_address);
        $machine_token = $token->get('token');

        // If we can't get the machine token through regular Terminus API,
        // then serialize all of the tokens and see if we can find it there.
        // This is a workaround for a Terminus bug.
        if (empty($machine_token)) {
            $raw_data = $tokens->serialize();
            if (isset($raw_data[$email_address]['token'])) {
                $machine_token = $raw_data[$email_address]['token'];
            }
        }

        return $machine_token;
    }

    /**
     * Return the list of available organizations
     */
    protected function availableOrgs()
    {
        $orgs = array_map(
            function ($org) {
                return $org->getLabel();
            },
            $this->session()->getUser()->getOrganizations()
        );
        return $orgs;
    }

    /**
     * Fetch the environment variable 'GITHUB_TOKEN', or throw an exception if it is not set.
     * @return string
     */
    protected function getRequiredGithubToken()
    {
        $github_token = getenv('GITHUB_TOKEN');
        if (empty($github_token)) {
            throw new TerminusException("Please generate a GitHub personal access token token, as described in https://help.github.com/articles/creating-an-access-token-for-command-line-use/. Then run: \n\nexport GITHUB_TOKEN=my_personal_access_token_value");
        }
        return $github_token;
    }

    /**
     * Fetch the environment variable 'CIRCLE_TOKEN', or throw an exception if it is not set.
     * @return string
     */
    protected function getRequiredCircleToken()
    {
        $circle_token = getenv('CIRCLE_TOKEN');
        if (empty($circle_token)) {
            throw new TerminusException("Please generate a Circle CI personal API token token, as described in https://circleci.com/docs/api/#authentication. Then run: \n\nexport CIRCLE_TOKEN=my_personal_api_token_value");
        }
        return $circle_token;
    }

    /**
     * Return the set of environment variables to save on the CI server.
     *
     * @param string $site_name
     * @param array $options
     * @return CIState
     */
    public function getCIEnvironment($site_name, $options)
    {
        $options += [
            'test-site-name' => '',
            'email' => '',
            'admin-password' => '',
            'admin-email' => '',
            'env' => [],
        ];

        $test_site_name = $options['test-site-name'];
        $git_email = $options['email'];
        $admin_password = $options['admin-password'];
        $admin_email = $options['admin-email'];
        $extra_env = $options['env'];

        if (empty($test_site_name)) {
            $test_site_name = $site_name;
        }

        // We should always be authenticated by the time we get here, but
        // we will test just to be sure.
        $terminus_token = $this->recoverSessionMachineToken();
        if (empty($terminus_token)) {
            throw new TerminusException("Please generate a Pantheon machine token, as described in https://pantheon.io/docs/machine-tokens/. Then log in via: \n\nterminus auth:login --machine-token=my_machine_token_value");
        }

        $ci_env = new CIState();

        $siteAttributes = (new SiteEnvironment())
            ->setSiteName($site_name)
            ->setSiteToken($terminus_token)
            ->setTestSiteName($test_site_name)
            ->setAdminPassword($admin_password)
            ->setAdminEmail($admin_email)
            ->setGitEmail($git_email);

        $ci_env->storeState('site', $siteAttributes);

        // Add in extra environment provided on command line via
        // --env='key=value' --env='another=v2'
        $envState = new ProviderEnvironment();
        foreach ($extra_env as $env) {
            list($key, $value) = explode('=', $env, 2) + ['',''];
            if (!empty($key) && !empty($value)) {
                $envState[$key] = $value;
            }
        }
        $ci_env->storeState('env', $envState);

        return $ci_env;
    }

    /**
     * Check to see if common options are valid. Provide sensible defaults
     * where values are unspecified.
     */
    protected function validateOptionsAndSetDefaults($options)
    {
        if (empty($options['admin-password'])) {
            $options['admin-password'] = mt_rand();
        }

        if (empty($options['email'])) {
            $options['email'] = exec('git config user.email');
        }

        if (empty($options['admin-email'])) {
            $options['admin-email'] = $options['email'];
        }

        // Catch errors in email address syntax
        $this->validateEmail('email', $options['email']);
        $this->validateEmail('admin-email', $options['admin-email']);

        return $options;
    }

    /**
     * Check to see if the provided email address is valid.
     */
    protected function validateEmail($emailOptionName, $emailValue)
    {
        // http://www.regular-expressions.info/email.html
        if (preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63}$/i', $emailValue)) {
            return;
        }

        throw new TerminusException("The email address '{email}'' is not valid. Please set a valid email address via 'git config --global user.email <address>', or override this setting with the --{option} option.", ['email' => $emailValue, 'option' => $emailOptionName]);
    }

    /**
     * Provide some simple aliases to reduce typing when selecting common repositories
     */
    protected function expandSourceAliases($source)
    {
        //
        // key:   org/project of template repository
        // value: list of aliases
        //
        // If the org is missing from the template repository, then
        // pantheon-systems is assumed.
        //
        $aliases = [
            'example-drops-8-composer' => ['d8', 'drops-8'],
            'example-drops-7-composer' => ['d7', 'drops-7'],

            // The wordpress template has not been created yet
            // 'example-wordpress-composer' => ['wp', 'wordpress'],
        ];

        $map = [strtolower($source) => $source];
        foreach ($aliases as $full => $shortcuts) {
            foreach ($shortcuts as $alias) {
                $map[$alias] = $full;
            }
        }

        return $map[strtolower($source)];
    }

    /**
     * Detect the upstream to use based on the contents of the source repository.
     * Upstream is irrelevant, save for the fact that this is the only way to
     * set the framework on Pantheon at the moment.
     */
    protected function autodetectUpstream($siteDir)
    {
        return 'Empty Upstream';
        // or 'Drupal 7' or 'WordPress'
        // return 'Drupal 8';
    }

    /**
     * Use composer create-project to create a new local copy of the source project.
     */
    protected function createFromSource($source, $target, $stability = '', $options = [])
    {
        if (is_dir($source)) {
            return $this->useExistingSourceDirectory($source, $options['preserve-local-repository']);
        }
        else {
            return $this->createFromSourceProject($source, $target, $stability);
        }
    }

    protected function useExistingSourceDirectory($source, $preserve_local_repository)
    {
        if ($preserve_local_repository) {
            if (!is_dir("$source/.git")) {
                throw new TerminusException('Specified --preserve-local-repository, but the directory {source} does not contains a .git directory.', compact('$source'));
            }
        }
        else {
            if (is_dir("$source/.git")) {
                throw new TerminusException('The directory {source} already contains a .git directory. Use --preserve-local-repository if you wish to use this existing repository.', compact('$source'));
            }
        }
        return $source;
    }

    protected function createFromSourceProject($source, $target, $stability = '')
    {
        $source_project = $this->sourceProjectFromSource($source);

        $this->log()->notice('Creating project and resolving dependencies.');

        // If the source is 'org/project:dev-branch', then automatically
        // set the stability to 'dev'.
        if (empty($stability) && preg_match('#:dev-#', $source)) {
            $stability = 'dev';
        }
        // Pass in --stability to `composer create-project` if user requested it.
        $stability_flag = empty($stability) ? '' : "--stability $stability";

        // Create a working directory
        $tmpsitedir = $this->tempdir('local-site');

        $this->passthru("composer create-project --working-dir=$tmpsitedir $source $target -n $stability_flag");
        $local_site_path = "$tmpsitedir/$target";
        return $local_site_path;
    }

    protected function createGitHub($target, $local_site_path, $github_org, $github_token)
    {
        // We need a different URL here if $github_org is an org; if no
        // org is provided, then we use a simpler URL to create a repository
        // owned by the currently-authenitcated user.
        $createRepoUrl = "orgs/$github_org/repos";
        $target_org = $github_org;
        if (empty($github_org)) {
            $createRepoUrl = 'user/repos';
            $userData = $this->curlGitHub('user', [], $github_token);
            $target_org = $userData['login'];
        }
        $target_project = "$target_org/$target";

        // Create a GitHub repository
        $this->log()->notice('Creating repository {repo}', ['repo' => $target_project]);
        $postData = ['name' => $target];
        $result = $this->curlGitHub($createRepoUrl, $postData, $github_token);

        // Create a git repository. Add an origin just to have the data there
        // when collecting the build metadata later. We use the 'pantheon'
        // remote when pushing.
        // TODO: Do we need to remove $local_site_path/.git? (-n in create-project should obviate this need) We preserve this here because it may be user-provided via --preserve-local-repository
        if (!is_dir("$local_site_path/.git")) {
            $this->passthru("git -C $local_site_path init");
        }
        $this->passthru("git -C $local_site_path remote add origin 'git@github.com:{$target_project}.git'");

        return $target_project;
    }

    /**
     * Given a source, such as:
     *    pantheon-systems/example-drops-8-composer:dev-lightning-fist-2
     * Return the 'project' portion, including the org, e.g.:
     *    pantheon-systems/example-drops-8-composer
     */
    protected function sourceProjectFromSource($source)
    {
        return preg_replace('/:.*/', '', $source);
    }

    /**
     * Make the initial commit to our new GitHub project.
     */
    protected function initialCommit($repositoryDir)
    {
        // Add the canonical repository files to the new GitHub project
        // respecting .gitignore.
        $this->passthru("git -C $repositoryDir add .");
        $this->passthru("git -C $repositoryDir commit -m 'Initial commit'");
        return $this->getHeadCommit($repositoryDir);
    }

    /**
     * Return the sha of the HEAD commit.
     */
    protected function getHeadCommit($repositoryDir)
    {
        return exec("git -C $repositoryDir rev-parse HEAD");
    }

    /**
     * Reset to the specified commit (or remove the last commit)
     */
    protected function resetToCommit($repositoryDir, $resetToCommit = 'HEAD^')
    {
        $this->passthru("git -C $repositoryDir reset --hard $resetToCommit");
    }

    /**
     * Make the initial commit to our new GitHub project.
     */
    protected function pushToGitHub($github_token, $target_project, $repositoryDir)
    {
        $remote_url = "https://$github_token:x-oauth-basic@github.com/${target_project}.git";
        $this->passthruRedacted("git -C $repositoryDir push --progress $remote_url master", $github_token);
    }

    // TODO: if we could look up the commandfile for
    // Pantheon\Terminus\Commands\Site\CreateCommand,
    // then we could just call its 'create' method
    public function siteCreate($site_name, $label, $upstream_id, $options = ['org' => null,])
    {
        if ($this->sites()->nameIsTaken($site_name)) {
            throw new TerminusException('The site name {site_name} is already taken.', compact('site_name'));
        }

        $workflow_options = [
            'label' => $label,
            'site_name' => $site_name
        ];
        $user = $this->session()->getUser();

        // Locate upstream
        $upstream = $user->getUpstreams()->get($upstream_id);

        // Locate organization
        if (!empty($org_id = $options['org'])) {
            $org = $user->getOrgMemberships()->get($org_id)->getOrganization();
            $workflow_options['organization_id'] = $org->id;
        }

        // Create the site
        $this->log()->notice('Creating a new Pantheon site {name}', ['name' => $site_name]);
        $workflow = $this->sites()->create($workflow_options);
        while (!$workflow->checkProgress()) {
            // @TODO: Add Symfony progress bar to indicate that something is happening.
        }

        // Deploy the upstream
        if ($site = $this->getSite($workflow->get('waiting_for_task')->site_id)) {
            $this->log()->notice('Deploying {upstream} to Pantheon site', ['upstream' => $upstream_id]);
            $workflow = $site->deployProduct($upstream->id);
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice('Deployed CMS');
        }

        return $this->getSite($site_name);
    }

    /**
     * Run an env:clone-content operation
     * @param Pantheon\Terminus\Models\Environment $target
     * @param string $from_name
     * @param bool $db_only
     * @param bool $files_only
     */
    public function cloneContent($target, $from_name, $db_only = false, $files_only = false)
    {
        // Clone files if we're only doing files, or if "only do db" is not set.
        if ($files_only || !$db_only) {
            $workflow = $target->cloneFiles($from_name);
            $this->log()->notice(
                "Cloning files from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }

        // Clone database if we're only doing the database, or if "only do files" is not set.
        if ($db_only || !$files_only) {
            $workflow = $target->cloneDatabase($from_name);
            $this->log()->notice(
                "Cloning database from {from_name} environment to {target_env} environment",
                ['from_name' => $from_name, 'target_env' => $target->getName()]
            );
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    protected function doInstallSite(
        $site_env_id,
        $composer_json = [],
        $site_install_options = [
            'account-mail' => '',
            'account-name' => '',
            'account-pass' => '',
            'site-mail' => '',
            'site-name' => ''
        ])
    {
        $command_template = $this->getInstallCommandTemplate($composer_json);
        return $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Install site", $site_install_options);
    }

    protected function runCommandTemplateOnRemoteEnv(
        $site_env_id,
        $command_templates,
        $operation_label,
        $options
    ) {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $this->log()->notice('{op} on {site}', ['op' => $operation_label, 'site' => $site_env_id]);

        // Set the target environment to sftp mode prior to running the command
        $this->connectionSet($env, 'sftp');

        foreach ((array)$command_templates as $command_template) {
            $metadata = array_map(function ($item) { return $this->escapeArgument($item); }, $options);
            $command_line = $this->interpolate($command_template, $metadata);
            $redacted_metadata = $this->redactMetadata($metadata, ['account-pass']);
            $redacted_command_line = $this->interpolate($command_template, $redacted_metadata);

            $this->log()->notice(' - {cmd}', ['cmd' => $redacted_command_line]);
            $result = $env->sendCommandViaSsh(
                $command_line,
                function ($type, $buffer) {
                }
            );
            $output = $result['output'];
            if ($result['exit_code']) {
                throw new TerminusException('{op} failed with exit code {status}', ['op' => $operation_label, 'status' => $result['exit_code']]);
            }
        }
    }

    protected function exportInitialConfiguration($site_env_id, $repositoryDir, $composer_json, $options)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $command_template = $this->getExportConfigurationTemplate($composer_json);
        if (empty($command_template)) {
            return;
        }

        // Run the 'export configuration' command
        $this->runCommandTemplateOnRemoteEnv($site_env_id, $command_template, "Export configuration", $options);

        // Commit the changes. Quicksilver is not set up to push these back
        // to GitHub from the dev branch, but we don't want to leave these changes
        // uncommitted.
        $env->commitChanges('Install site and export configuration.');

        // TODO: How do we know where the configuration will be exported to?
        // Perhaps we need to export to a temporary directory where we control
        // the path. Perhaps export to ':tmp/config' instead of ':code/config'.
        $this->rsync($site_env_id, ':code/config', $repositoryDir);

        $this->passthru("git -C $repositoryDir add config");
        $this->passthru("git -C $repositoryDir commit -m 'Export configuration'");
    }

    /**
     * Remove sensitive information from a metadata array.
     */
    protected function redactMetadata($metadata, $keys_to_redact)
    {
        foreach ($keys_to_redact as $key) {
            $metadata[$key] = '"[REDACTED]"';
        }
        return $metadata;
    }

    /**
     * Determine the command to use to install the site.
     */
    protected function getInstallCommandTemplate($composer_json)
    {
        if (isset($composer_json['extra']['build-env']['install-cms'])) {
            return $composer_json['extra']['build-env']['install-cms'];
        }
        // TODO: Select a different default template based on the cms type (Drupal or WordPress).
        $defaultTemplate = 'drush site-install --yes --account-mail={account-mail} --account-name={account-name} --account-pass={account-pass} --site-mail={site-mail} --site-name={site-name}';

        return $defaultTemplate;
    }

    /**
     * Determine the command to use to export configuration.
     */
    protected function getExportConfigurationTemplate($composer_json)
    {
        if (isset($composer_json['extra']['build-env']['export-configuration'])) {
            return $composer_json['extra']['build-env']['export-configuration'];
        }

        return '';
    }

    /**
     * Read the composer.json file from the provided site directory.
     */
    protected function getComposerJson($siteDir)
    {
        $composer_json_file = "$siteDir/composer.json";
        if (!file_exists($composer_json_file)) {
            return [];
        }
        return json_decode(file_get_contents($composer_json_file), true);
    }

    /**
     * Escape one command-line arg
     *
     * @param string $arg The argument to escape
     * @return RowsOfFields
     */
    protected function escapeArgument($arg)
    {
        // Omit escaping for simple args.
        if (preg_match('/^[a-zA-Z0-9_-]*$/', $arg)) {
            return $arg;
        }
        return ProcessUtils::escapeArgument($arg);
    }

    /**
     * Push code to Pantheon -- common routine used by 'create-project', 'create' and 'push-code' commands.
     */
    public function pushCodeToPantheon(
        $site_env_id,
        $multidev = '',
        $repositoryDir = '',
        $label = '')
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $multidev = empty($multidev) ? $env_id : $multidev;
        $branch = ($multidev == 'dev') ? 'master' : $multidev;
        $env_label = $multidev;
        if (!empty($label)) {
            $env_label = $label;
        }

        if (empty($repositoryDir)) {
            $repositoryDir = getcwd();
        }

        // Sanity check: only push from directories that have .git and composer.json
        foreach (['.git', 'composer.json'] as $item) {
            if (!file_exists("$repositoryDir/$item")) {
                throw new TerminusException('Cannot push from {dir}: missing {item}.', ['dir' => $repositoryDir, 'item' => $item]);
            }
        }

        $this->log()->notice('Pushing code to {multidev} using branch {branch}.', ['multidev' => $multidev, 'branch' => $branch]);

        // Fetch the site id also
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Check to see if '$multidev' already exists on Pantheon.
        $environmentExists = $site->getEnvironments()->has($multidev);

        // Add a remote named 'pantheon' to point at the Pantheon site's git repository.
        // Skip this step if the remote is already there (e.g. due to CI service caching).
        $this->addPantheonRemote($env, $repositoryDir);
        // $this->passthru("git -C $repositoryDir fetch pantheon");

        // Record the metadata for this build
        $metadata = $this->getBuildMetadata($repositoryDir);
        $this->recordBuildMetadata($metadata, $repositoryDir);

        // Remove any .git directories added by composer from the set of files
        // being committed. Ideally, there will be none. We cannot allow any to
        // remain, though, as git will interpret these as submodules, which
        // will prevent the contents of directories containing .git directories
        // from being added to the main repository.
        $finder = new Finder();
        $fs = new Filesystem();
        $fs->remove(
          $finder
            ->directories()
            ->in("$repositoryDir")
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->depth('> 0')
            ->name('.git')
            ->getIterator()
        );

        // Create a new branch and commit the results from anything that may
        // have changed. We presume that the source repository is clean of
        // any unwanted files prior to the build step (e.g. after a clean
        // checkout in a CI environment.)
        $this->passthru("git -C $repositoryDir checkout -B $branch");
        $this->passthru("git -C $repositoryDir add --force -A .");

        // Now that everything is ready, commit the build artifacts.
        $this->passthru("git -C $repositoryDir commit -q -m 'Build assets for $env_label.'");

        // If the environment does exist, then we need to be in git mode
        // to push the branch up to the existing multidev site.
        if ($environmentExists) {
            $target = $site->getEnvironments()->get($multidev);
            $this->connectionSet($target, 'git');
        }

        // Push the branch to Pantheon
        $preCommitTime = time();
        $this->passthru("git -C $repositoryDir push --force -q pantheon $branch");

        // If the environment already existed, then we risk encountering
        // a race condition, because the 'git push' above will fire off
        // an asynchronous update of the existing update. If we switch to
        // sftp mode before this sync is completed, then the converge that
        // sftp mode kicks off will corrupt the environment.
        if ($environmentExists) {
            $this->waitForCodeSync($preCommitTime, $site, $multidev);
        }

        return $metadata;
    }

    protected function projectFromRemoteUrl($url)
    {
        return preg_replace('#[^:/]*[:/]([^/:]*/[^.]*)\.git#', '\1', str_replace('https://', '', $url));
    }


    protected function preserveEnvsWithOpenPRs($remoteUrl, $oldestEnvironments, $multidev_delete_pattern, $auth = '')
    {
        $project = $this->projectFromRemoteUrl($remoteUrl);
        $branchList = $this->branchesForOpenPullRequests($project, $auth);
        return $this->filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern);
    }

    function branchesForOpenPullRequests($project, $auth = '')
    {
        $data = $this->curlGitHub("repos/$project/pulls?state=open", [], $auth);

        $branchList = array_map(
            function ($item) {
                return $item['head']['ref'];
            },
            $data
        );

        return $branchList;
    }

    protected function createAuthorizationHeaderCurlChannel($url, $auth = '')
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: pantheon/terminus-build-tools-plugin'
        ];

        if (!empty($auth)) {
            $headers[] = "Authorization: token $auth";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }

    protected function setCurlChannelPostData($ch, $postData, $force = false)
    {
        if (!empty($postData) || $force) {
            $payload = json_encode($postData);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    public function execCurlRequest($ch, $service = 'API request')
    {
        $result = curl_exec($ch);
        if(curl_errno($ch))
        {
            throw new TerminusException(curl_error($ch));
        }
        $data = json_decode($result, true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $errors = [];
        if (isset($data['errors'])) {
            foreach ($data['errors'] as $error) {
                $errors[] = $error['message'];
            }
        }
        if ($httpCode && ($httpCode >= 300)) {
            $errors[] = "Http status code: $httpCode";
        }

        $message = isset($data['message']) ? "{$data['message']}." : '';

        if (!empty($message) || !empty($errors)) {
            throw new TerminusException('{service} error: {message} {errors}', ['service' => $service, 'message' => $message, 'errors' => implode("\n", $errors)]);
        }

        return $data;
    }

    protected function createGitHubCurlChannel($uri, $auth = '')
    {
        $url = "https://api.github.com/$uri";
        return $this->createAuthorizationHeaderCurlChannel($url, $auth);
    }

    protected function createGitHubPostChannel($uri, $postData = [], $auth = '')
    {
        $ch = $this->createGitHubCurlChannel($uri, $auth);
        $this->setCurlChannelPostData($ch, $postData);

        return $ch;
    }

    protected function createGitHubDeleteChannel($uri, $auth = '')
    {
        $ch = $this->createGitHubCurlChannel($uri, $auth);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        return $ch;
    }
/*
    public function curlGitHub($uri, $postData = [], $auth = '')
    {
        $this->log()->notice('Call GitHub API: {uri}', ['uri' => $uri]);
        $ch = $this->createGitHubPostChannel($uri, $postData, $auth);
        return $this->execCurlRequest($ch, 'GitHub');
    }
*/

    protected function curlGitHub($uri, $data = [], $auth = '')
    {
        $this->logger->notice('Call GitHub API: {uri}', ['uri' => $uri]);

        $url = "https://api.github.com/$uri";

        $headers = [
            'Content-Type: application/json',
            'User-Agent: pantheon/terminus-build-tools-plugin'
        ];

        if (!empty($auth)) {
            $headers[] = "Authorization: token $auth";
        }

        $client = new \GuzzleHttp\Client();
        $res = $client->request('POST', $url, [
            'headers' => $headers,
            'form_params' => $data,
        ]);
        return $res->getStatusCode();
    }

    // TODO: At the moment, this takes multidev environment names,
    // e.g.:
    //   pr-dc-worka
    // And compares them against a list of branches, e.g.:
    //   dc-workaround
    //   lightning-fist-2
    //   composer-merge-pantheon
    // In its current form, the 'pr-' is stripped from the beginning of
    // the environment name, and then a 'begins-with' test is done. This
    // is not perfect, but if it goes wrong, the result will be that a
    // multidev environment that should have been eligible for deletion will
    // not be deleted.
    //
    // This could be made better if we could fetch the build-metadata.json
    // file from the repository root of each multidev environment, which would
    // give us the correct branch name for every environment. We could do
    // this without too much trouble via rsync; this might be a little slow, though.
    protected function preserveEnvsWithGitHubBranches($oldestEnvironments, $multidev_delete_pattern)
    {
        $remoteBranch = 'origin';

        // Update the local repository -- prune / add remote branches.
        // We could use `git remote prune origin` to only prune remote branches.
        $this->passthru('git remote update --prune origin');

        // List all of the remote branches
        $outputLines = $this->exec('git branch -ar');

        // Remove branch lines that do not begin with 'origin/'
        $outputLines = array_filter(
            $outputLines,
            function ($item) use ($remoteBranch) {
                return preg_match("%^ *$remoteBranch/%", $item);
            }
        );

        // Strip the 'origin/' from the beginning of each branch line
        $outputLines = array_map(
            function ($item) use ($remoteBranch) {
                return preg_replace("%^ *$remoteBranch/%", '', $item);
            },
            $outputLines
        );

        return $this->filterBranches($oldestEnvironments, $outputLines, $multidev_delete_pattern);
    }

    protected function filterBranches($oldestEnvironments, $branchList, $multidev_delete_pattern)
    {
        // Filter environments that have matching remote branches in origin
        return array_filter(
            $oldestEnvironments,
            function ($item) use ($branchList, $multidev_delete_pattern) {
                $match = $item;
                // If the name is less than the maximum length, then require
                // an exact match; otherwise, do a 'starts with' test.
                if (strlen($item) < 11) {
                    $match .= '$';
                }
                // Strip the multidev delete pattern from the beginning of
                // the match. The multidev env name was composed by prepending
                // the delete pattern to the branch name, so this recovers
                // the branch name.
                $match = preg_replace("%$multidev_delete_pattern%", '', $match);
                // Constrain match to only match from the beginning
                $match = "^$match";

                // Find items in $branchList that match $match.
                $matches = preg_grep ("%$match%i", $branchList);
                return empty($matches);
            }
        );
    }

    protected function deleteEnv($env, $deleteBranch = false)
    {
        $workflow = $env->delete(['delete_branch' => $deleteBranch,]);
        $workflow->wait();
        if ($workflow->isSuccessful()) {
            $this->log()->notice('Deleted the multidev environment {env}.', ['env' => $env->id,]);
        } else {
            throw new TerminusException($workflow->getMessage());
        }
    }

    /**
     * Return a list of multidev environments matching the provided
     * pattern, sorted with oldest first.
     *
     * @param string $site_id Site to check.
     * @param string $multidev_delete_pattern Regex of environments to select.
     */
    protected function oldestEnvironments($site_id, $multidev_delete_pattern)
    {
        // Get a list of all of the sites
        $env_list = $this->getSite($site_id)->getEnvironments()->serialize();

        // Filter out the environments that do not match the multidev delete pattern
        $env_list = array_filter(
            $env_list,
            function ($item) use ($multidev_delete_pattern) {
                return preg_match("%$multidev_delete_pattern%", $item['id']);
            }
        );

        // Sort the environments by creation date, with oldest first
        uasort(
            $env_list,
            function ($a, $b) {
                if ($a['created'] == $b['created']) {
                    return 0;
                }
                return ($a['created'] < $b['created']) ? -1 : 1;
            }
        );

        return $env_list;
    }

    /**
     * Create a new multidev environment
     *
     * @param string $site_env Source site and environment.
     * @param string $multidev Name of environment to create.
     */
    public function create($site_env, $multidev)
    {
        list($site, $env) = $this->getSiteEnv($site_env, 'dev');
        $this->log()->notice("Creating multidev {env} for site {site}", ['site' => $site->getName(), 'env' => $multidev]);
        $workflow = $site->getEnvironments()->create($multidev, $env);
        while (!$workflow->checkProgress()) {
            // TODO: Add workflow progress output
        }
        $this->log()->notice($workflow->getMessage());
    }

    /**
     * Set the connection mode to 'sftp' or 'git' mode, and wait for
     * it to complete.
     *
     * @param Pantheon\Terminus\Models\Environment $env
     * @param string $mode
     */
    public function connectionSet($env, $mode)
    {
        $workflow = $env->changeConnectionMode($mode);
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }
    }

    /**
     * Wait for a workflow to complete.
     *
     * @param int $startTime Ignore any workflows that started before the start time.
     * @param string $workflow The workflow message to wait for.
     */
    protected function waitForCodeSync($startTime, $site, $env_name)
    {
        $this->waitForWorkflow($startTime, $site, $env_name);
    }

    protected function waitForWorkflow($startTime, $site, $env_name, $expectedWorkflowDescription = '', $maxWaitInSeconds = 60)
    {
        if (empty($expectedWorkflowDescription)) {
            $expectedWorkflowDescription = "Sync code on \"$env_name\"";
        }

        $startWaiting = time();
        while(time() - $startWaiting < $maxWaitInSeconds) {
            $workflow = $this->getLatestWorkflow($site);
            $workflowCreationTime = $workflow->get('created_at');
            $workflowDescription = $workflow->get('description');

            if (($workflowCreationTime > $startTime) && ($expectedWorkflowDescription == $workflowDescription)) {
                $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
                if ($workflow->isSuccessful()) {
                    return;
                }
            }
            else {
                $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $workflowDescription, 'expected' => $expectedWorkflowDescription]);
            }
            // Wait a bit, then spin some more
            sleep(5);
        }
    }

    /**
     * Fetch the info about the currently-executing (or most recently completed)
     * workflow operation.
     */
    protected function getLatestWorkflow($site)
    {
        $workflows = $site->getWorkflows()->fetch(['paged' => false,])->all();
        $workflow = array_shift($workflows);
        $workflow->fetchWithLogs();
        return $workflow;
    }

    /**
     * Return the metadata for this build.
     *
     * @return string[]
     */
    public function getBuildMetadata($repositoryDir)
    {
        return [
          'url'         => exec("git -C $repositoryDir config --get remote.origin.url"),
          'ref'         => exec("git -C $repositoryDir rev-parse --abbrev-ref HEAD"),
          'sha'         => $this->getHeadCommit($repositoryDir),
          'comment'     => exec("git -C $repositoryDir log --pretty=format:%s -1"),
          'commit-date' => exec("git -C $repositoryDir show -s --format=%ci HEAD"),
          'build-date'  => date("Y-m-d H:i:s O"),
        ];
    }

    /**
     * Write the build metadata into the build results prior to committing them.
     *
     * @param string[] $metadata
     */
    public function recordBuildMetadata($metadata, $repositoryDir)
    {
        $buildMetadataFile = "$repositoryDir/build-metadata.json";
        $metadataContents = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $this->log()->notice('Set {file} to {metadata}.', ['metadata' => $metadataContents, 'file' => basename($buildMetadataFile)]);

        file_put_contents($buildMetadataFile, $metadataContents);
    }

    /**
     * Iterate through the different environments, and keep fetching their
     * metadata until we find one that has a 'url' component.
     *
     * @param string $site_id The site to operate on
     * @param stirng[] $oldestEnvironments List of environments
     * @return string
     */
    protected function retrieveRemoteUrlFromBuildMetadata($site_id, $oldestEnvironments)
    {
        foreach ($oldestEnvironments as $env) {
            try {
                $metadata = $this->retrieveBuildMetadata("{$site_id}.{$env}");
                if (isset($metadata['url'])) {
                    return $metadata['url'];
                }
            }
            catch(\Exception $e) {
            }
        }
        return '';
    }

    /**
     * Get the build metadata from a remote site.
     *
     * @param string $site_env_id
     * @return string[]
     */
    public function retrieveBuildMetadata($site_env_id)
    {
        $src = ':code/build-metadata.json';
        $dest = '/tmp/build-metadata.json';

        $status = $this->rsync($site_env_id, $src, $dest);
        if ($status != 0) {
            return [];
        }

        $metadataContents = file_get_contents($dest);
        $metadata = json_decode($metadataContents, true);

        unlink($dest);

        return $metadata;
    }

    /**
     * Add or refresh the 'pantheon' remote
     */
    public function addPantheonRemote($env, $repositoryDir)
    {
        // Refresh the remote is already there (e.g. due to CI service caching), just in
        // case. If something changes, this info is NOT removed by "rebuild without cache".
        if ($this->hasPantheonRemote($repositoryDir)) {
            passthru("git -C $repositoryDir remote remove pantheon");
        }
        $connectionInfo = $env->connectionInfo();
        $gitUrl = $connectionInfo['git_url'];
        $this->passthru("git -C $repositoryDir remote add pantheon $gitUrl");
    }

    /**
     * Check to see if there is a remote named 'pantheon'
     */
    protected function hasPantheonRemote($repositoryDir)
    {
        exec("git -C $repositoryDir remote show", $output);
        return array_search('pantheon', $output) !== false;
    }

    /**
     * Substitute replacements in a string. Replacements should be formatted
     * as {key} for raw value, or [[key]] for shell-escaped values.
     *
     * @param string $message
     * @param string[] $context
     * @return string[]
     */
    protected function interpolate($message, array $context)
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace[sprintf('{%s}', $key)] = $val;
                $replace[sprintf('[[%s]]', $key)] = ProcessUtils::escapeArgument($val);
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Call rsync to or from the specified site.
     *
     * @param string $site_env_id Remote site
     * @param string $src Source path to copy from. Start with ":" for remote.
     * @param string $dest Destination path to copy to. Start with ":" for remote.
     */
    protected function rsync($site_env_id, $src, $dest)
    {
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        $siteAddress = "$env_id.$site_id@appserver.$env_id.$site_id.drush.in:";

        $src = preg_replace('/^:/', $siteAddress, $src);
        $dest = preg_replace('/^:/', $siteAddress, $dest);

        $this->log()->notice('Rsync {src} => {dest}', ['src' => $src, 'dest' => $dest]);
        passthru("rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222' $src $dest >/dev/null 2>&1", $status);

        return $status;
    }

    /**
     * Return 'true' if the specified file was changed in the provided commit.
     */
    protected function commitChangesFile($commit, $file)
    {
        $outputLines = $this->exec("git show --name-only $commit $file");

        return !empty($outputLines);
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command, $loggedCommand = '')
    {
        $result = 0;
        $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
        // TODO: How noisy do we want to be?
        $this->log()->notice("Running {cmd}", ['cmd' => $loggedCommand]);
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $loggedCommand, 'status' => $result]);
        }
    }

    function passthruRedacted($command, $secret)
    {
        $loggedCommand = str_replace($secret, 'REDACTED', $command);
        $command .= " | sed -e 's/$secret/REDACTED/g'";

        return $this->passthru($command, $loggedCommand);
    }

    /**
     * Call exec; throw an exception on failure.
     *
     * @param string $command
     * @return string[]
     */
    protected function exec($command)
    {
        $result = 0;
        $this->log()->notice("Running {cmd}", ['cmd' => $command]);
        exec($command, $outputLines, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
        return $outputLines;
    }

    // Create a temporary directory
    public function tempdir($prefix='php', $dir=FALSE)
    {
        $this->registerCleanupFunction();
        $tempfile=tempnam($dir ? $dir : sys_get_temp_dir(), $prefix ? $prefix : '');
        if (file_exists($tempfile)) {
            unlink($tempfile);
        }
        mkdir($tempfile);
        chmod($tempfile, 0700);
        if (is_dir($tempfile)) {
            $this->tmpDirs[] = $tempfile;
            return $tempfile;
        }
    }

    /**
     * Register our shutdown function if it hasn't already been registered.
     */
    public function registerCleanupFunction()
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        // Insure that $workdir will be deleted on exit.
        register_shutdown_function([$this, 'cleanup']);
        $registered = true;
    }

    // Delete our work directory on exit.
    public function cleanup()
    {
        if (empty($this->tmpDirs)) {
            return;
        }

        $fs = new Filesystem();
        $fs->remove($this->tmpDirs);
    }
}
