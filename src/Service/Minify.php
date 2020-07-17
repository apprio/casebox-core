<?php

namespace Casebox\CoreBundle\Service;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Minify
 */
class Minify
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @var array
     */
    protected $assets;

    /**
     * Authentication constructor
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Generates minified files
     *
     * @param array $groupName css|js
     * @param OutputInterface $output
     */
    public function execute($groupName, OutputInterface $output = null)
    {
        $public = $this->container->get('kernel')->locateResource('@CaseboxCoreBundle/Resources/public');
        $assets = $this->getDefaultAssests();

        \Minify::setDocRoot($public);

        $options['quiet'] = true;

        foreach ($assets as $group => $files) {
            $ext = (substr($group, 0, 2) == 'js') ? 'js' : 'css';
            if ($ext !== $groupName) {
                continue;
            }

            $options['files'] = [];
            foreach ($files as $key => $file) {
                $options['files'][$key] = $public.'/'.$file;
            }

            // Normal
            $options['debug'] = false;
            $output = \Minify::serve(new \Minify_Controller_Files(), $options);
            file_put_contents($public.'/min/'.$group.'.'.$ext, $output['content']);

            // Debug
            $options['debug'] = true;
            $output = \Minify::serve(new \Minify_Controller_Files(), $options);
            file_put_contents($public.'/min/'.$group.'-debug.'.$ext, $output['content']);

            unset($output);
        }
    }

    public function getDefaultAssests()
    {
        $files = [
            'css' => [
                'css/CB.css',
                'css/template_icons.css',
                'css/tasks.css',
                'css/taskbar.css',
                'css/casebox.css',
                'css/preview.css',
                'css/common.css',
                'css/facets.css',
                'css/obj_plugins.css',
                'css/activity-stream-view.css',
                'css/extensible-all.css',
                'css/calendar.css',
                'css/calendar-colors.css',
                'js/highlight/default.css',
                'css/fix.css',
            ],

            'csspreview' => [
                'css/preview.css',
                'css/tasks.css',
                'css/template_icons.css',
                'css/common.css',
            ],

            'csstheme' => [
                'css/default/ribbon.css',
                'css/default/theme.css',
            ],

            'js' => [
                'js/CB/DB/Models.js',
                'js/iso8601.min.js',
                'js/md5/spark-md5.min.js',
                'js/md5/Ext.ux.FileMD5.js',
                'js/ux/Ext.ux.WebkitEntriesIterator.js',
                'js/CB/calendar/data/EventMappings.js',
                'js/CB/calendar/data/EventModel.js',
                'js/CB/calendar/data/CalendarMappings.js',
                'js/CB/calendar/data/CalendarModel.js',
                'js/CB/calendar/data/MemoryCalendarStore.js',
                'js/CB/calendar/data/MemoryEventStore.js',
                'js/CB/calendar/util/Date.js',
                'js/CB/calendar/util/WeekEventRenderer.js',
                'js/CB/calendar/dd/StatusProxy.js',
                'js/CB/calendar/dd/DragZone.js',
                'js/CB/calendar/dd/DropZone.js',
                'js/CB/calendar/dd/DayDragZone.js',
                'js/CB/calendar/dd/DayDropZone.js',
                'js/CB/calendar/form/field/CalendarCombo.js',
                'js/CB/calendar/form/field/DateRange.js',
                'js/CB/calendar/form/field/ReminderCombo.js',
                'js/CB/calendar/form/EventDetails.js',
                'js/CB/calendar/form/EventWindow.js',
                'js/CB/calendar/template/BoxLayout.js',
                'js/CB/calendar/template/DayBody.js',
                'js/CB/calendar/template/DayHeader.js',
                'js/CB/calendar/template/Month.js',
                'js/CB/calendar/view/AbstractCalendar.js',
                'js/CB/calendar/view/MonthDayDetail.js',
                'js/CB/calendar/view/Month.js',
                'js/CB/calendar/view/DayHeader.js',
                'js/CB/calendar/view/DayBody.js',
                'js/CB/calendar/view/Day.js',
                'js/CB/calendar/view/Week.js',
                'js/CB/calendar/CalendarPanel.js',
                // 'js/CB/calendar/extensible-all-debug.js',
                'js/CB/CB.Login.js',
                'js/CB/CB.GenericForm.js',
                'js/CB/CB.ObjectsField.js',
                'js/CB/browser/Actions.js',
                'js/CB/browser/Tree.js',
                'js/CB/browser/ViewContainer.js',
                'js/CB/browser/view/Interface.js',
                'js/CB/browser/view/Grid.js',
                'js/CB/browser/view/ActivityStream.js',
                'js/CB/browser/view/grid/toolbar/Paging.js',
                'js/CB/browser/view/grid/feature/Grouping.js',
                'js/CB/browser/view/Calendar.js',
                'js/CB/browser/view/Charts.js',
                'js/CB/browser/view/Dashboard.js',
                'js/CB/browser/view/Map.js',
                'js/CB/browser/view/Pivot.js',
                'js/CB/CB.VerticalEditGrid.js',
                'js/CB/CB.VerticalSearchEditGrid.js',
                'js/CB/CB.PasteFromWord.js',
                'js/CB/CB.FileUploadWindow.js',
                'js/CB/plugin/CustomInterface.js',
                'js/CB/plugin/field/DropDownList.js',
                'js/CB/plugin/field/RemainingCharsHint.js',
				'js/CB/plugin/PlacesProxy.js',
                'js/ux/Ext.ux.htmlEditor.js',
                'js/ux/Ext.ux.plugins.defaultButton.js',
                // 'js/ux/Ext.ux.plugins.IconCombo.js',
                'js/CB/CB.TextEditWindow.js',
                'js/CB/CB.HtmlEditWindow.js',
                'js/CB/facet/Base.js',
                'js/CB/facet/Text.js',
                'js/CB/facet/List.js',
                'js/CB/facet/Calendar.js',
                'js/CB/facet/UsersColor.js',
                'js/CB/CB.Clipboard.js',
                'js/CB/CB.FilterPanel.js',
                'js/CB/favorites/Panel.js',
                // 'js/CB/favorites/Button.js',
                'js/CB/plugin/dd/FilesDropZone.js',
                'js/CB/CB.Uploader.js',
                'js/CB/CB.NoteUploader.js',
                'js/CB/CB.Security.js',
                'js/CB/CB.UsersGroups.js',
                'js/CB/CB.Account.js',
                'js/CB/Validators.js',
                'js/CB/Util.js',
                'js/CB/CB.DD.js',
                'js/CB/DD/Tree.js',
                'js/CB/DD/Grid.js',
                'js/CB/DD/Panel.js',
                'js/CB/CB.VerticalEditGridHelperTree.js',
                'js/CB/DB/ObjectsStore.js',
                'js/CB/DB/DirectObjectsStore.js',
                'js/CB/DB/TemplateStore.js',
                'js/CB/ViewPort.js',
                'js/CB/plugin/Panel.js',
                'js/CB/object/view/Preview.js',
                'js/CB/object/view/Properties.js',
                'js/CB/object/view/View.js',
                'js/CB/object/view/Stub.js',
                'js/CB/object/edit/Form.js',
                'js/CB/object/edit/Window.js',
                'js/CB/object/edit/View.js',
                'js/CB/object/widget/TitleView.js',
                'js/CB/object/ViewContainer.js',
                'js/CB/search/edit/Panel.js',
                'js/CB/search/edit/Window.js',
                'js/CB/search/Field.js',
                'js/CB/object/plugin/Base.js',
                'js/CB/object/plugin/Thumb.js',
                'js/CB/object/plugin/Comments.js',
                'js/CB/object/plugin/ContentItems.js',
                'js/CB/object/plugin/Files.js',
                'js/CB/object/plugin/Html.js',
                'js/CB/object/plugin/ObjectProperties.js',
                'js/CB/object/plugin/SystemProperties.js',
                'js/CB/object/plugin/Meta.js',
                'js/CB/object/plugin/Tasks.js',
                'js/CB/object/plugin/Versions.js',
                'js/CB/object/plugin/CurrentVersion.js',
                'js/CB/object/plugin/TimeTracking.js',
                'js/CB/file/edit/Window.js',
                'js/CB/CB.WebdavWindow.js',
                'js/CB/CB.WindowManager.js',
                'js/CB/state/DBProvider.js',
                'js/CB/field/Comment.js',
                'js/CB/field/CommentLight.js',
                'js/CB/widget/Breadcrumb.js',
                'js/CB/widget/DataSorter.js',
                'js/CB/widget/LeafletPanel.js',
                'js/CB/widget/LeafletWindow.js',
                'js/CB/widget/TaskBar.js',
                'js/CB/widget/block/Base.js',
                'js/CB/widget/block/Chart.js',
                'js/CB/widget/block/Grid.js',
                'js/CB/widget/block/Map.js',
                'js/CB/widget/block/Pivot.js',
                'js/CB/widget/block/Template.js',

                'js/CB/app.js',
                'js/CB/controller/Browsing.js',
                'js/CB/controller/History.js',
                'js/CB/object/field/editor/Form.js',
                'js/CB/object/field/editor/Tag.js',
                'js/CB/view/BoundListKeyNav.js',
                'js/CB/notifications/View.js',
                'js/CB/notifications/Reports.js',
                'js/CB/notifications/SettingsWindow.js',
            ],

            'jsoverrides' => [
                'js/overrides/Ajax.js',
                'js/overrides/Patches.js',
                'js/overrides/calendar/dd/DayDropZone.js',
                'js/overrides/calendar/form/field/DateRange.js',
                'js/overrides/calendar/template/BoxLayout.js',
                'js/overrides/calendar/view/Day.js',
                'js/overrides/calendar/view/DayBody.js',
                'js/overrides/calendar/view/Month.js',
                'js/overrides/calendar/CalendarPanel.js',
                // ,'js/overrides/direct/JsonProvider.js',
                'js/overrides/data/Store.js',
                'js/overrides/form/field/Text.js',
                'js/overrides/grid/plugin/CellEditing.js',
                'js/overrides/grid/CellEditor.js',
                'js/overrides/grid/GridPanel.js',
                'js/overrides/tree/ViewDragZone.js',
                'js/overrides/toolbar/Toolbar.js',
                'js/overrides/util/Collection.js',
                'js/overrides/util/AbstractMixedCollection.js',
                'js/overrides/util/Format.js',
            ],

            'jsplugins' => [
                'js/CB/plugin/DisplayColumns.js',
                'js/CB/plugin/ExportInit.js',
                'js/CB/plugin/ExportButton.js',
                'js/CB/plugin/SearchInit.js',
                'js/CB/plugin/SearchButton.js',
                'js/CB/plugin/SearchForm.js',
                'js/CB/plugin/SearchResultForm.js',
            ],
        ];

        return $files;
    }
}
