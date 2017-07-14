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

/**
 * Env Merge Command
 */
class EnvMergeCommand extends BuildToolsBase
{

    /**
     * @command build:env:merge
     * @alias build-env:merge
     * @param string $site_env_id The site and env to merge and delete
     * @option label What to name the environment in commit comments
     * @option delete Delete the multidev environment after merging.
     */
    public function mergeBuildEnv($site_env_id, $options = ['label' => '', 'delete' => false])
    {
        // c.f. merge-pantheon-multidev script
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $env_label = $env_id;
        if (!empty($options['label'])) {
            $env_label = $options['label'];
        }

        // If we are building against the 'dev' environment, then simply
        // commit the changes once the PR is merged.
        if ($env_id == 'dev') {
            $env->commitChanges("Build assets for $env_label.");
            return;
        }

        $preCommitTime = time();

        // When using build-env:merge, we expect that the dev environment
        // should stay in git mode. We will switch it to git mode now to be sure.
        $dev_env = $site->getEnvironments()->get('dev');
        $this->connectionSet($dev_env, 'git');

        // Branch name to use for temporary work when merging
        $tmpMergeBranch = 'temp-local-copy-of-pantheon-master';

        // Replace the entire contents of the master branch with the branch we just tested.
        // @todo, is a merge even necessary when the master branch will be force pushed over?
        $this->passthru('git fetch pantheon');
        $this->passthru('git checkout pantheon/' . $env_label);
        $this->passthru("git checkout -B $tmpMergeBranch");
        $this->passthru("git merge -q -m 'Merge build assets from test $env_label.' --strategy=ours pantheon/master");

        // Push our changes back to the dev environment, replacing whatever was there before.
        $this->passthru("git push --force -q pantheon $tmpMergeBranch:master");
        passthru("git branch -D $tmpMergeBranch");

        // Wait for the dev environment to finish syncing after the merge.
        $this->waitForCodeSync($preCommitTime, $site, 'dev');

        // Once the build environment is merged, delete it if we don't need it any more
        if ($options['delete']) {
            $this->deleteEnv($env, true);
        }
    }
}
