<?php
namespace BF13\Bundle\BusinessApplicationBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use BF13\Bundle\BusinessApplicationBundle\Entity\ValueList;
use Symfony\Component\Console\Input\ArrayInput;

class SyncProjectCommand extends ContainerAwareCommand
{

    public function configure()
    {
        $this->setName('bf13:sync:project');

        $this->setDescription('Synchronize a project');
        $this->setDefinition(array(
            new InputOption('make-scope', 'm', InputOption::VALUE_REQUIRED, 'Generate synchronisation file for a scope'),
            new InputOption('bypass-sync', 'by', InputOption::VALUE_NONE, 'Bypass synchronisation (for prod action)'),
            new InputOption('data-load', 'dl', InputOption::VALUE_NONE, 'Load value list'),
            new InputOption('init-bundles', 'ib', InputOption::VALUE_NONE, 'Generate bundles'),
            new InputOption('init-db', 'id', InputOption::VALUE_NONE, 'Create the database'),
            new InputOption('update-db', 'ud', InputOption::VALUE_NONE, 'Update the database schema'),
            new InputOption('data-load', 'dl', InputOption::VALUE_NONE, 'Load value list'),
            new InputOption('scope', 'c', InputOption::VALUE_REQUIRED, 'Define the synchronisation scope'),
            new InputOption('release', 'r', InputOption::VALUE_REQUIRED, 'Retrieve the selected release'),
            new InputOption('latest', 'l', InputOption::VALUE_NONE, 'Retrieve the last release else retrieve the release defined into release.bf13 file')
        ));

        $this->setHelp(<<<EOT
Sync a project
EOT
);
    }

    /**
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(array('##########################################', '# Synchronisation du projet #', '##########################################'));

        $this->output = $output;

        $make_scope = $input->getOption('make-scope');
        $scope = $input->getOption('scope');
        $bypass = $input->getOption('bypass-sync');

        $release = $this->defineSelectedRelease($input);

        $this->output->writeln('- Release: ' . $release);

        if ($make_scope) {

            $this->makeScope($make_scope, $release);

        } else {

            if(!$bypass)
            {
                $this->syncProject($release, $scope, $input->getOption('init-bundles'));
            }

            if($input->getOption('init-db'))
            {
                $this->generateBusinessEntities();

                $this->initDatabase();
            }

            if($input->getOption('update-db'))
            {
                $this->generateBusinessEntities();

                $this->updateDatabase();
            }

            if($input->getOption('data-load'))
            {
                $this->loadValueList();
            }
        }

        $output->writeln('Terminé');
    }

    protected function loadValueList()
    {
        $this->output->writeln('- Chargement des listes de valeurs');

        $entityManager = $this->getContainer()->get('doctrine')->getManager();

        $conn = $entityManager->getConnection();

        $conn->query('DELETE FROM valuelist');

        $root_dir = $this->getContainer()->getParameter('kernel.root_dir') . '/../src';

        $finder = new Finder();

        $finder->files()->name('*.valuelist.yml')->in($root_dir);

        foreach ($finder as $file) {
            $yaml = new Yaml();

            $this->output->writeln('+ ' . $file->getFilename());
            $yaml_data = $yaml->parse($file->getRealpath());

            $DataValueList = $this->prepareValueList($yaml_data['value_list']);
        }
    }

    protected function prepareValueList($data)
    {
        $entityManager = $this->getContainer()->get('doctrine')->getManager();

        foreach ($data as $row) {

            $DataValueList = new ValueList();
            $DataValueList->setVlkey($row['key']);
            $DataValueList->setData($row['data']);

            $entityManager->persist($DataValueList);
        }

        $entityManager->flush();

        return $DataValueList;
    }

    protected function defineSelectedRelease($input)
    {
        if($input->getOption('latest'))
        {
            return 'latest';
        }

        if($release = $input->getOption('release'))
        {
            return $release;
        }

        $dir = realpath($this->getContainer()->getParameter('kernel.root_dir') . '/../');

        $release_file = $dir . '/release.BF13';

        if(file_exists($release_file) && $release = (string) file_get_contents($release_file))
        {
            return $release;
        }

        return 'latest';
    }

    protected function makeScope($scope, $release = null)
    {
        $filepath = $this->buildZipFile('project.tmp.zip', $scope, $release);

        $dir = realpath($this->getContainer()->getParameter('kernel.cache_dir')) . DIRECTORY_SEPARATOR . 'bf13-dev' . DIRECTORY_SEPARATOR . $scope;

        $fs = new Filesystem();

        if (is_dir($dir)) {

            $fs->remove($dir);
        }

        $fs->mkdir($dir);

        $this->extractZipFile($filepath, $dir);

        $finder = new Finder();

        $finder->files()->in($dir);

        $files = array(
            'include' => array(),
            'exclude' => array()
        );

        foreach ($finder as $file) {

            $files['include'][] = $file->getRelativePathname();
        }

        $yaml = new Yaml();

        $yaml_data = $yaml->dump($files);

        $dir = realpath($this->getContainer()->getParameter('kernel.root_dir')) . DIRECTORY_SEPARATOR . 'bf13-dev' . DIRECTORY_SEPARATOR .'scope';

        if (! is_dir($dir)) {

            $fs->mkdir($dir);
        }

        $filescope = sprintf('%s.scope.yml', $scope);
        $this->output->writeln(array(
            sprintf('Création du fichier "%s"', $filescope)
        ));

        file_put_contents($dir . DIRECTORY_SEPARATOR . $filescope, $yaml_data);
    }

    protected function copyFiles($from_dir, $target_dir)
    {
        $this->output->writeln('- sync files');

        $fs = new Filesystem();

        $finder = new Finder();

        $finder->files()->in($from_dir);

        foreach ($finder as $file) {

            $base_file_strategy = false;

            $src_pattern = $file->getRelativePathname();

            $filename = $file->getFilename();

            $new_file = $from_dir . DIRECTORY_SEPARATOR . $src_pattern;

            if(0 === strpos($filename, '$$'))
            {
                $base_file_strategy = true;

                $src_pattern = str_replace($filename, substr($filename, 2), $src_pattern);
            }

            $existing_file = $target_dir . DIRECTORY_SEPARATOR . $src_pattern;

            if (is_file($existing_file))
            {
                $existing_file_content = file_get_contents($existing_file);

            } else {

                $existing_file_content = false;
            }

            if (is_file($new_file))
            {
                $new_file_content = file_get_contents($new_file);

            } else {

                $new_file_content = false;
            }

            switch (true) {

                //ne rien faire si fichier existant et stratégie de réplication active
                case $base_file_strategy === true && $existing_file_content !== false && trim($existing_file_content) != '':

                    break;

                //copie si nouveau fichier
                case $existing_file_content === false && $new_file_content !== false:

                    $fs->copy($new_file, $existing_file, true);

                    $this->output->writeln(array(
                        'Copie du fichier: ' . $src_pattern
                    ));
                    break;

                //ne rien faire si fichier existant et sans source
                case $new_file_content === false && $existing_file_content !== false:

                    break;

                //copie si fichier existant différent de la source et sans stratégie de réplication
                case $new_file_content !== false && $new_file_content !== $existing_file_content:

                    $fs->copy($new_file, $existing_file, true);

                    $this->output->writeln(array(
                        'Copie du fichier: ' . $src_pattern
                    ));
                    break;

                //ne rien faire si fichier existant identique à la source
                case $new_file_content === $existing_file_content:
                default:
            }
        }
    }

    protected function initDatabase()
    {
        $this->output->writeln('- generate database');

        $command = $this->getApplication()->find('doctrine:database:create');

        $arguments = array(
            'command' => 'doctrine:database:create',
        );

        $input = new ArrayInput($arguments);

        $returnCode = $command->run($input, $this->output);

        $this->output->writeln('- create schema');

        $command = $this->getApplication()->find('doctrine:schema:create');

        $arguments = array(
            'command' => 'doctrine:schema:create',
        );

        $input = new ArrayInput($arguments);

        $returnCode = $command->run($input, $this->output);
    }

    protected function updateDatabase()
    {
        $this->output->writeln('- update database');

        $command = $this->getApplication()->find('doctrine:schema:update');

        $arguments = array(
            'command' => 'doctrine:schema:update',
            '--force' => true
        );

        $input = new ArrayInput($arguments);

        $returnCode = $command->run($input, $this->output);
    }

    protected function generateBusinessEntities()
    {
        $root_dir = $this->getContainer()->getParameter('kernel.root_dir') . '/../';

        $file = $root_dir . 'app/config/bf13/bundles.yml';

        if(! file_exists($file))
        {
            throw new \Exception(sprintf('File "%s" not found !', $file));
        }

        $yaml = new Yaml();

        $yaml_data = $yaml->parse($file);

        foreach($yaml_data['bundles'] as $bundle)
        {
            if(false === strpos($bundle, 'BusinessBundle'))
            {
                continue;
            }

            $path = sprintf('%s/src', $root_dir);

            $path_entities = $path . '/' . $bundle . '/Resources/config/doctrine';

            if(! is_dir($path_entities))
            {
                $this->output->writeln(sprintf('[!] folder "%s/Resources/config/doctrine" does not exists', $bundle));

                continue;
            }

            $this->output->writeln(sprintf('- generate "%s" entities', $bundle));

            $command = $this->getApplication()->find('doctrine:generate:entities');

            $arguments = array(
                'command' => 'doctrine:generate:entities',
                'name' => $bundle,
                '--path' => $path,
                '--no-backup' => true
            );

            $input = new ArrayInput($arguments);

            $returnCode = $command->run($input, $this->output);
        }
    }

    protected function syncProject($release = null, $scope = null, $initbundles = false)
    {
        $filepath = $this->buildZipFile('project.tmp.zip', $scope, $release);

        $include = null;

        if ($scope) {

            $dir = realpath($this->getContainer()->getParameter('kernel.root_dir')) . DIRECTORY_SEPARATOR . 'bf13-dev' . DIRECTORY_SEPARATOR .'scope';

            $filescope = sprintf('%s/%s.scope.yml', $dir, $scope);

            if (! is_file($filescope)) {

                throw new \Exception(sprintf('Fichier "%s" introuvable !', $filescope));
            }

            $content = file_get_contents($filescope);

            $yaml = new Yaml();

            $data = $yaml->parse($content);

            $include = $data['include'];
        }

        $cache_dir = $this->getContainer()->getParameter('kernel.cache_dir') . '/bf13_extract/';
        $root_dir = $this->getContainer()->getParameter('kernel.root_dir') . '/../';

        $fs = new Filesystem();

        if (is_dir($cache_dir)) {

            $fs->remove($cache_dir);
        }

        $fs->mkdir($cache_dir);

        $this->extractZipFile($filepath, $cache_dir, $include);

        $this->checkBundles($cache_dir, $root_dir, $initbundles);

        $this->copyFiles($cache_dir, $root_dir);
    }

    protected function checkBundles($cache_dir, $root_dir, $initbundles = false)
    {
        $this->output->writeln('- check bundles');

        $file = $cache_dir . 'app/config/bf13/bundles.yml';

        if(! file_exists($file))
        {
            throw new \Exception(sprintf('File "%s" not found !', $file));
        }

        $yaml = new Yaml();

        $yaml_data = $yaml->parse($file);

        foreach($yaml_data['bundles'] as $bundle)
        {
            $target = $root_dir . 'src/' . $bundle;

            if($initbundles && is_dir($target))
            {
                $this->output->writeln(sprintf('[i] Bundle "%s" already exists !', $bundle));

                continue;

            } else if(!$initbundles && is_dir($target))
            {
                continue;

            } else if(!$initbundles && ! is_dir($target))
            {
                throw new \Exception(sprintf("Bundle \"%s\" is undefined !\n relaunch command with --init-debug option", $bundle));
            }

            $this->generateBundle($bundle);

            $this->purgeNewBundle($target);
        }
    }

    protected function generateBundle($bundle)
    {
        $this->output->writeln('[x] generate bundle: ' . $bundle);

        $command = $this->getApplication()->find('generate:bundle');

        $bundle_sections = explode('/', $bundle);

        $arguments = array(
            'command' => 'generate:bundle',
            '--namespace'    => $bundle,
            '--bundle-name'    => $bundle_sections[0] . end($bundle_sections),
            '--dir'  => 'src',
            '--format'  => 'yml',
            '--structure'  => false,
            '--no-interaction'  => true,
        );

        $input = new ArrayInput($arguments);

        $returnCode = $command->run($input, $this->output);
    }

    protected function purgeNewBundle($bundle_dir)
    {
        $this->output->writeln('- cleanup ' . $bundle_dir);

        $files = array(
            $bundle_dir . '/Resources/config/routing.yml',
            $bundle_dir . '/Resources/config/services.yml',
            $bundle_dir . '/Resources/public',
            $bundle_dir . '/Resources/views',
            $bundle_dir . '/Controller',
            $bundle_dir . '/Tests/Controller',
        );

        $fs = new Filesystem();

        $fs->remove($files);
    }

    protected function buildZipFile($filename, $scope, $release = null)
    {
        $api_params = $this->getContainer()->getParameter('bf13_business_application');

        $dest = $api_params['api_workdir'];

        $fs = new Filesystem();

        if (! is_dir($dest)) {

            throw new \Exception('Le paramètre "workdir" est incorrecte !');
        }

        $dest .= '/bf13_sync';

        if (is_dir($dest)) {
            $fs->remove($dest);
        }

        $fs->mkdir($dest);

        $filename = $dest . DIRECTORY_SEPARATOR . $filename;

        switch($release)
        {
        	case 'latest':
                $api_action = '/export/exportlastrelease/{token}';
                $api_data = array(
                    '{token}' => $api_params['api_token']
                );
                break;

        	default:
                $api_action = '/export/exportrelease/{release}/{token}';
                $api_data = array(
                    '{token}' => $api_params['api_token'],
                    '{release}' => $release
                );
        }

        $api_call = strtr($api_action, $api_data);

        $api_url = $api_params['api_url'] . $api_call;

        $ZipFile = $this->getZipFile($api_url, $api_params['api_auth']);

        $this->saveZipFile($filename, $ZipFile);

        return $filename;
    }

    protected function getZipFile($url, $auth)
    {
        // retrieve zipfile
        $this->output->writeln('- Connexion & téléchargement');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/zip'
        ));

        if ('' != trim($auth)) {
            curl_setopt($ch, CURLOPT_USERPWD, $auth);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $http_response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        switch (substr($http_status, 0, 1)) {
            case 2:
                return $http_response;
                break;

            case 4:
                throw new \Exception('! Erreur HTTP ' . $http_status . ": " . $http_response);
                break;

            case 5:
                if(503 == $http_status)
                {

                    $this->output->writeln($http_response);

                    throw new \Exception('! Erreur HTTP : La construction a généré une erreur !');
                }

                throw new \Exception('! Erreur HTTP ' . $http_status);
                break;

            default:
                throw new \Exception('! Erreur HTTP ' . $http_status);
        }
    }

    protected function saveZipFile($filename, $content)
    {
        // save zipfile
        $this->output->writeln('- Enregistrement');
        @unlink($filename);
        file_put_contents($filename, $content);
    }

    protected function extractZipFile($filename, $extract_folder, $include = null)
    {
        // extract files
        $this->output->writeln('- Extraction');
        $za = new \ZipArchive();
        $opened = $za->open($filename);

        if($opened !== true)
        {
            $zip_error = array(
                \ZipArchive::ER_EXISTS => 'Le fichier existe déjà',
                \ZipArchive::ER_INCONS => 'L\'archive ZIP est inconsistante',
                \ZipArchive::ER_INVAL => 'Argument invalide',
                \ZipArchive::ER_MEMORY => 'Erreur de mémoire',
                \ZipArchive::ER_NOENT => 'Le fichier n\'existe pas',
                \ZipArchive::ER_NOZIP => 'Le fichier n\'est pas une archive valide',
                \ZipArchive::ER_OPEN => 'Impossible d\'ouvrir le fichier',
                \ZipArchive::ER_READ => 'Erreur lors de la lecture',
                \ZipArchive::ER_SEEK => 'Erreur de position',
            );
            throw new \Exception(sprintf('! ZIP erreur : "%s"', $zip_error[$opened]));
        }

        $files = null;
        if ($include) {
            for ($i = 0; $i < $za->numFiles; $i ++) {
                $entry = $za->getNameIndex($i);
                // Use strpos() to check if the entry name contains the directory we want to extract
                if (in_array($entry, $include)) {
                    // Add the entry to our array if it in in our desired directory
                    $files[] = $entry;
                }
            }
        }

        $za->extractTo($extract_folder, $files);
        $za->close();
        $this->output->writeln('--> ' . $extract_folder);
    }
}