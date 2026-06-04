<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\App;
use Elabftw\Elabftw\Metadata;
use Elabftw\Elabftw\PermissionsHelper;
use Elabftw\Enums\AccessType;
use Elabftw\Enums\Classification;
use Elabftw\Enums\Currency;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\Meaning;
use Elabftw\Enums\Orderby;
use Elabftw\Enums\RequestableAction;
use Elabftw\Enums\Sort;
use Elabftw\Exceptions\ResourceNotFoundException;
use Elabftw\Interfaces\ControllerInterface;
use Elabftw\Models\AbstractEntity;
use Elabftw\Models\Config;
use Elabftw\Models\ExperimentsStatus;
use Elabftw\Models\ExtraFieldsKeys;
use Elabftw\Models\FavTags;
use Elabftw\Models\ItemsStatus;
use Elabftw\Models\ItemsTypes;
use Elabftw\Models\RequestActions;
use Elabftw\Models\StorageUnits;
use Elabftw\Models\TeamGroups;
use Elabftw\Models\TeamTags;
use Elabftw\Models\Templates;
use Elabftw\Models\UserRequestActions;
use Elabftw\Params\DisplayParams;
use Elabftw\Params\BaseQueryParams;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Override;
use Symfony\Component\HttpFoundation\InputBag;

use function array_column;
use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function preg_replace;
use function sprintf;
use const JSON_THROW_ON_ERROR;

/**
 * For displaying an entity in show, view or edit mode
 * Preloads shared data like templates (experiments/items), statuses, team info.
 */
abstract class AbstractEntityController implements ControllerInterface
{
    // is the main category of current entity type, can be experimentsCategoryArr or itemsCategoryArr
    protected array $categoryArr = array();

    protected array $experimentsStatusArr = array();

    protected array $itemsStatusArr = array();

    protected array $statusArr = array();

    protected array $visibilityArr = array();

    protected array $classificationArr = array();

    protected array $meaningArr = array();

    protected array $requestableActionArr = array();

    protected array $currencyArr = array();

    protected array $scopedTeamgroupsArr = array();

    public function __construct(protected App $App, protected AbstractEntity $Entity)
    {
        $TeamGroups = new TeamGroups($this->Entity->Users);
        $PermissionsHelper = new PermissionsHelper();
        $this->visibilityArr = $PermissionsHelper->getAssociativeArray();
        $this->classificationArr = Classification::getAssociativeArray();
        $this->meaningArr = Meaning::getAssociativeArray();
        $this->requestableActionArr = RequestableAction::getAssociativeArray();
        $this->currencyArr = Currency::getAssociativeArray();
        $this->scopedTeamgroupsArr = $TeamGroups->readScopedTeamgroups();
        $ExperimentsStatus = new ExperimentsStatus($App->Teams);
        $this->experimentsStatusArr = $ExperimentsStatus->readAll($ExperimentsStatus->getQueryParams(new InputBag(array('limit' => 9999))));
        $ItemsStatus = new ItemsStatus($this->App->Teams);
        $this->itemsStatusArr = $ItemsStatus->readAll($ItemsStatus->getQueryParams(new InputBag(array('limit' => 9999))));
    }

    #[Override]
    public function getResponse(): Response
    {
        return match ($this->App->Request->query->getAlpha('mode')) {
            'view' => $this->view(),
            'edit' => $this->edit(),
            'changelog' => $this->changelog(),
            default => $this->show(),
        };
    }

    /**
     * Show mode (several items displayed). Default view.
     */
    public function show(): Response
    {
        // used to get all tags for top page tag filter
        $TeamTags = new TeamTags($this->App->Users, $this->App->Users->userData['team']);
        $ExtraFieldsKeys = new ExtraFieldsKeys($this->App->Users, '', -1);

        // only show public to anon
        if ($this->App->Session->get('is_anon')) {
            $this->Entity->isAnon = true;
        }

        // must be before the call to readShow
        if (($this->App->Users->userData['always_show_owned'] ?? null) === 1) {
            $this->Entity->alwaysShowOwned = true;
        }

        // read all based on query parameters or user defaults
        $orderBy = Orderby::tryFrom($this->App->Users->userData['orderby']) ?? Orderby::Lastchange;
        $skipOrderPinned = $this->App->Request->query->getBoolean('skip_pinned');
        $DisplayParams = new DisplayParams(
            requester: $this->App->Users,
            entityType: $this->Entity->entityType,
            query: $this->App->Request->query,
            orderby: $orderBy,
            sort: Sort::tryFrom($this->App->Users->userData['sort']) ?? Sort::Desc,
            limit: $this->App->Users->userData['limit_nb'],
            skipOrderPinned: $skipOrderPinned,
        );
        $itemsArr = $this->Entity->readShow($DisplayParams);
        if (count($itemsArr) === 0 && $this->shouldUseUiExampleDemo()) {
            $itemsArr = $this->buildUiExampleDemoItems();
        }
        $itemsArr = $this->decorateShowItems($itemsArr);

        // get tags separately
        $tagsArr = array();
        if (!empty($itemsArr)) {
            $tagsArr = $this->Entity->getTags($itemsArr);
        }

        // store the query parameters in the Session
        $this->App->Session->set('lastquery', $this->App->Request->getQueryString());

        // FAVTAGS
        $FavTags = new FavTags($this->App->Users);
        $favTagsArr = $FavTags->readAll();

        $template = 'show.html';
        $UserRequestActions = new UserRequestActions($this->App->Users);

        $renderArr = array(
            'DisplayParams' => $DisplayParams,
            'Entity' => $this->Entity,
            'categoryArr' => $this->categoryArr,
            'statusArr' => $this->statusArr,
            'favTagsArr' => $favTagsArr,
            'itemsArr' => $itemsArr,
            'pageTitle' => $this->getPageTitle(),
            'metakeyArrForSelect' => array_column($ExtraFieldsKeys->readAll(), 'extra_fields_key'),
            'requestActionsArr' => $UserRequestActions->readAllFull(),
            'scopedTeamgroupsArr' => $this->scopedTeamgroupsArr,
            'tagsArr' => $tagsArr,
            // get all the tags for the top search bar
            'tagsArrForSelect' => $TeamTags->readAll(),
            'usersArr' => $this->App->Users->readAllFromTeam(),
            'visibilityArr' => $this->visibilityArr,
        );
        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render($template, $renderArr));

        return $Response;
    }

    protected function decorateShowItems(array $itemsArr): array
    {
        foreach ($itemsArr as &$item) {
            $item['list_state_title'] = $item['status_title'] ?? '';
            $item['list_state_color'] = $item['status_color'] ?? 'bdbdbd';
            $item['fleet_runtime_state'] = '';

            if (!array_key_exists('metadata', $item) || !is_string($item['metadata']) || $item['metadata'] === '') {
                continue;
            }

            try {
                $metadata = json_decode($item['metadata'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($metadata) || !isset($metadata['fleet_v2_runtime']) || !is_array($metadata['fleet_v2_runtime'])) {
                continue;
            }

            $runtime = $metadata['fleet_v2_runtime'];
            $runtimeState = $runtime['device_state'] ?? '';
            if (!is_string($runtimeState) || $runtimeState === '') {
                continue;
            }

            $item['fleet_runtime_state'] = $runtimeState;
            $item['list_state_title'] = $this->humanizeRuntimeState($runtimeState);
            if (empty($item['status_color'])) {
                $item['list_state_color'] = $this->runtimeStateColor($runtimeState);
            }
        }

        return $itemsArr;
    }

    private function shouldUseUiExampleDemo(): bool
    {
        return in_array('ui-example', $this->App->Request->query->all('tags'), true);
    }

    private function buildUiExampleDemoItems(): array
    {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        if ($this->Entity->entityType === EntityType::Experiments) {
            return array(
                $this->buildUiExampleDemoItem(
                    id: -101,
                    title: 'Fleet UI Example - Queued',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 1,
                    statusTitle: 'Queued',
                    statusColor: 'ef6c00',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
                $this->buildUiExampleDemoItem(
                    id: -102,
                    title: 'Fleet UI Example - Running',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 2,
                    statusTitle: 'Running',
                    statusColor: '1565c0',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
                $this->buildUiExampleDemoItem(
                    id: -103,
                    title: 'Fleet UI Example - Prepared',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 3,
                    statusTitle: 'Prepared',
                    statusColor: '00838f',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
                $this->buildUiExampleDemoItem(
                    id: -104,
                    title: 'Fleet UI Example - Uploading',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 4,
                    statusTitle: 'Uploading',
                    statusColor: '6a1b9a',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
                $this->buildUiExampleDemoItem(
                    id: -105,
                    title: 'Fleet UI Example - Done',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 5,
                    statusTitle: 'Done',
                    statusColor: '2e7d32',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
                $this->buildUiExampleDemoItem(
                    id: -106,
                    title: 'Fleet UI Example - Failed',
                    categoryTitle: 'Fleet Run',
                    categoryColor: '455a64',
                    statusId: 6,
                    statusTitle: 'Failed',
                    statusColor: 'c62828',
                    date: $today,
                    modifiedAt: $now,
                    tags: array('ui-example', 'experiment-state'),
                ),
            );
        }

        return array(
            $this->buildUiExampleDemoItem(
                id: -201,
                title: 'Device MONAD-01',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 1,
                statusTitle: 'Operational',
                statusColor: '2e7d32',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'ONLINE',
            ),
            $this->buildUiExampleDemoItem(
                id: -202,
                title: 'Device MONAD-02',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 2,
                statusTitle: 'Waiting',
                statusColor: 'ef6c00',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'WAITING_POLICY',
            ),
            $this->buildUiExampleDemoItem(
                id: -203,
                title: 'Device MONAD-03',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 3,
                statusTitle: 'Ready',
                statusColor: '1565c0',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'PREPARED',
            ),
            $this->buildUiExampleDemoItem(
                id: -204,
                title: 'Device MONAD-04',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 4,
                statusTitle: 'Assigned',
                statusColor: '3949ab',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'ASSIGNED',
            ),
            $this->buildUiExampleDemoItem(
                id: -205,
                title: 'Device MONAD-05',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 5,
                statusTitle: 'Processed',
                statusColor: '6a1b9a',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'REPORT_ACCEPTED',
            ),
            $this->buildUiExampleDemoItem(
                id: -206,
                title: 'Device MONAD-06',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 6,
                statusTitle: 'Duplicate',
                statusColor: '5e35b1',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'REPORT_DUPLICATE',
            ),
            $this->buildUiExampleDemoItem(
                id: -207,
                title: 'Device MONAD-07',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 7,
                statusTitle: 'Maintenance mode',
                statusColor: 'c62828',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'REPORT_REJECTED',
            ),
            $this->buildUiExampleDemoItem(
                id: -208,
                title: 'Device MONAD-08',
                categoryTitle: 'Measurement Device',
                categoryColor: '00897b',
                statusId: 8,
                statusTitle: 'Maintenance mode',
                statusColor: 'c62828',
                date: $today,
                modifiedAt: $now,
                tags: array('ui-example', 'device-state'),
                runtimeState: 'PREPARE_REJECTED',
            ),
        );
    }

    private function buildUiExampleDemoItem(
        int $id,
        string $title,
        string $categoryTitle,
        string $categoryColor,
        int $statusId,
        string $statusTitle,
        string $statusColor,
        string $date,
        string $modifiedAt,
        array $tags,
        string $runtimeState = '',
    ): array {
        $metadata = array();
        if ($runtimeState !== '') {
            $metadata['fleet_v2_runtime'] = array('device_state' => $runtimeState);
        }

        return array(
            'id' => $id,
            'title' => $title,
            'custom_id' => null,
            'date' => $date,
            'created_at' => $modifiedAt,
            'modified_at' => $modifiedAt,
            'category' => 1,
            'category_title' => $categoryTitle,
            'category_color' => $categoryColor,
            'status' => $statusId,
            'status_title' => $statusTitle,
            'status_color' => $statusColor,
            'metadata' => $metadata === array() ? '' : json_encode($metadata, JSON_THROW_ON_ERROR),
            'team' => $this->App->Users->userData['team'],
            'rating' => 0,
            'userid' => $this->App->Users->userData['userid'],
            'fullname' => $this->App->Users->userData['fullname'],
            'locked' => 1,
            'state' => 1,
            'canread' => '{}',
            'canwrite' => '{}',
            'canread_is_immutable' => 0,
            'canwrite_is_immutable' => 0,
            'timestamped' => 0,
            'next_step' => '',
            'is_pinned' => 0,
            'is_demo' => true,
            'demo_tags' => $tags,
        );
    }

    private function humanizeRuntimeState(string $runtimeState): string
    {
        $normalized = preg_replace('/[_\s]+/', ' ', $runtimeState) ?? $runtimeState;
        return ucwords(strtolower(trim($normalized)));
    }

    private function runtimeStateColor(string $runtimeState): string
    {
        return match (strtoupper(trim($runtimeState))) {
            'ONLINE', 'IDLE', 'REPORT_ACCEPTED', 'REPORT_DUPLICATE' => '2e7d32',
            'PREPARED', 'POLICY_READY', 'POLICY_CACHED' => '1565c0',
            'ASSIGNED' => '3949ab',
            'WAITING_POLICY' => 'ef6c00',
            'REPORT_REJECTED' => 'ad1457',
            'PREPARE_REJECTED' => 'c62828',
            default => '6c757d',
        };
    }

    abstract protected function getPageTitle(): string;

    // empty by default because only for items
    protected function getEntityProcurementRequestsArr(): array
    {
        return array();
    }

    /**
     * View mode (one item displayed)
     */
    protected function view(): Response
    {
        $RequestActions = new RequestActions($this->App->Users, $this->Entity);
        // the mode parameter is for the uploads tpl
        $renderArr = array(
            'categoryArr' => $this->categoryArr,
            'classificationArr' => $this->classificationArr,
            'currencyArr' => $this->currencyArr,
            'Entity' => $this->Entity,
            'entityProcurementRequestsArr' => $this->getEntityProcurementRequestsArr(),
            'entityRequestActionsArr' => $RequestActions->readAllFull(),
            'pageTitle' => $this->getPageTitle(),
            'mode' => 'view',
            'hideTitle' => true,
            'teamsArr' => $this->App->Teams->readAllVisible(),
            'scopedTeamgroupsArr' => $this->scopedTeamgroupsArr,
            'timestamperFullname' => $this->Entity->getTimestamperFullname(),
            'lockerFullname' => $this->Entity->getLockerFullname(),
            'meaningArr' => $this->meaningArr,
            'requestableActionArr' => $this->requestableActionArr,
            'storageUnitsArr' => new StorageUnits($this->App->Users, Config::getConfig()->configArr['inventory_require_edit_rights'] === '1')->readAllRecursive(),
            'usersArr' => $this->App->Users->readAllActiveFromTeam(),
            'visibilityArr' => $this->visibilityArr,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('view.html', $renderArr));

        return $Response;
    }

    /**
     * Edit mode
     */
    protected function edit(): Response
    {
        // redirect to view mode if we don't have edit access
        if ($this->Entity->isReadOnly) {
            if (!isset($this->Entity->id)) {
                throw new ResourceNotFoundException();
            }
            return new RedirectResponse(sprintf(
                '%s%sid=%d',
                $this->Entity->entityType->toPage(),
                '?mode=view&',
                $this->Entity->id,
            ), Response::HTTP_SEE_OTHER); // 303
        }
        // all entities are in exclusive edit mode as of march 2025. See #5568
        $this->Entity->ExclusiveEditMode->activate();

        $TeamTags = new TeamTags($this->App->Users);
        $RequestActions = new RequestActions($this->App->Users, $this->Entity);

        $Metadata = new Metadata($this->Entity->entityData['metadata']);
        $baseQueryParams = new BaseQueryParams($this->App->Request->query);
        // used in field builder modal, TODO we might want to make it dynamic loading later
        $Templates = new Templates($this->App->Users);
        $ItemsTypes = new ItemsTypes($this->App->Users);
        $DisplayParamsTemplates = new DisplayParams($this->App->Users, EntityType::Templates);
        $DisplayParamsItemsTypes = new DisplayParams($this->App->Users, EntityType::ItemsTypes);
        $renderArr = array(
            'categoryArr' => $this->categoryArr,
            'classificationArr' => $this->classificationArr,
            'currencyArr' => $this->currencyArr,
            'Entity' => $this->Entity,
            'entityProcurementRequestsArr' => $this->getEntityProcurementRequestsArr(),
            'entityRequestActionsArr' => $RequestActions->readAllFull(),
            'hideTitle' => true,
            'metadataGroups' => $Metadata->getGroups(),
            'mode' => 'edit',
            'pageTitle' => $this->getPageTitle(),
            'statusArr' => $this->statusArr,
            'teamsArr' => $this->App->Teams->readAllVisible(),
            'teamTagsArr' => $TeamTags->readAll($baseQueryParams),
            'scopedTeamgroupsArr' => $this->scopedTeamgroupsArr,
            'meaningArr' => $this->meaningArr,
            'requestableActionArr' => $this->requestableActionArr,
            'storageUnitsArr' => new StorageUnits($this->App->Users, Config::getConfig()->configArr['inventory_require_edit_rights'] === '1')->readAllRecursive(),
            'templatesArr' => $Templates->readAllSimple($DisplayParamsTemplates),
            'itemsTemplatesArr' => $ItemsTypes->readAllSimple($DisplayParamsItemsTypes),
            'usersArr' => $this->App->Users->readAllActiveFromTeam(),
            'visibilityArr' => $this->visibilityArr,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('edit.html', $renderArr));
        return $Response;
    }

    protected function changelog(): Response
    {
        // check permissions
        $this->Entity->canOrExplode(AccessType::Read);

        $renderArr = array(
            'changes' => $this->Entity->entityData['changelog'],
            'Entity' => $this->Entity,
        );

        $Response = new Response();
        $Response->prepare($this->App->Request);
        $Response->setContent($this->App->render('changelog.html', $renderArr));
        return $Response;
    }
}
