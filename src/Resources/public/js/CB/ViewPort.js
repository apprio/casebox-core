Ext.namespace('CB');

Ext.define('CB.ViewPort', {
    extend: 'Ext.Viewport'

    ,layout: 'fit'
    ,border: false

    ,initComponent: function(){

        this.initComponents();

        Ext.apply(this, {
            items: {
                lbar: App.mainLBar
                ,layout: 'fit'
                ,border: false
                ,items: [
                    {
                        region: 'center'
                        ,layout: 'border'
                        ,border: false
                        ,bbar: App.mainStatusBar
                        ,items: [
                            App.mainLPanel
                            ,{
                                layout: 'fit'
                                ,region: 'center'
                                ,bodyStyle: 'border: 0'
                                ,tbar: App.mainTBar
                                ,items: [App.mainTabPanel]
                            }
                        ]
                    }
                ]
            }
            ,listeners: {
                scope: this
                ,login: this.onLogin
                ,fileupload: this.onFileUpload
                ,filedownload: this.onFilesDownload
                ,createobject: this.createObject
                ,deleteobject: this.onDeleteObject
                ,opencalendar: this.openCalendar
                ,useradded: this.onUsersChange
                ,userdeleted: this.onUsersChange
            }
        });

        this.callParent(arguments);
    }

    /**
     * init main components for this viewport
     * @return void
     */
    ,initComponents: function() {

        this.initButtons();

        var items = [ {
                xtype: 'panel'
                ,border: false
                ,style: 'border-bottom: 1px solid #5f5f5f'
                ,bodyStyle: 'background: transparent'
                ,height: 49
                ,items: [
                    this.buttons.toggleLeftRegion
                ]
            }
            ,this.buttons.create
            ,this.buttons.toggleFilterPanel
        ];

        //add config buttons if present
        if(Ext.isObject(App.config.leftRibbonButtons)) {
            Ext.iterate(
                App.config.leftRibbonButtons
                ,function(k, cfg) {
                    cfg.scale = 'large';
                    cfg.scope = this;
                    cfg.handler = this.onLeftRibbonButtonClick;
                    items.push(cfg);
                }
                ,this
            );
        }

        //application main left bar (left docked)
        App.mainLBar = new Ext.Toolbar({
            cls: 'ribbon-black'
            ,autoWidth: true
            ,dock: 'left'
            ,items: items
            ,plugins: [{
                ptype: 'CBPluginSearchButton'
            }]
        });

        //main left panel where the tree is added
        App.mainLPanel = new Ext.Panel({
            region: 'west'
            ,layout: 'card'
            ,width: 250
            ,split: {
                size: 3
                ,collapsible: false
            }
            ,collapsible: true
            ,collapseMode: 'mini'
            ,hideCollapseTool: true
            ,header: false
            ,animCollapse: false
            ,plain: true
            ,border: false
            ,bodyBorder: false
            ,bodyStyle: 'background-color: #F4F4F4'
            ,bodyCls: 'main-nav'
            ,defaults: {
                border: false
                ,bodyBoder: false
                ,bodyStyle: 'background-color: #F4F4F4'
                ,lazyrender: true
                ,scrollable: true
            }
            ,stateful: true
            ,stateId: 'mAc'
            ,stateEvents: ['resize']
            ,tbar: Ext.create({
                xtype: 'panel'
                ,height: 51
                ,border: false
                ,style: 'background: #f4f4f4; text-align: center; border-bottom: 1px solid #99bce8 !important'
                ,bodyStyle: 'background: #f4f4f4'
                ,html: '<img alt="ACF ECMRS Logo" src="/logo.png" style="padding: 9px" />'
            })
            ,getState: function(){
                var rez = {
                    // collapsed: this.collapsed
                    width: this.width
                };

                return rez;
            }
        });

        // prepare components for main top toolbar
        this.breadcrumb = Ext.create({
            xtype: 'CBBreadcrumb'
            ,cls: 'fs18'
            ,flex: 1
        });

        this.searchField = Ext.create({
                xtype: 'CBSearchField'
                ,cls: 'mainSearch'
                ,emptyText: L.SearchCaseBox
                ,minListWidth: 150
                ,width: 350
            }
        );

        App.mainTBar = new Ext.Toolbar({
            height: 50
            ,style:'background: #f4f4f4; border: 0;'
            ,items: [
                this.breadcrumb
                ,this.searchField
                ,this.buttons.toggleNotificationsView  //Dumbing down Apprio
				,this.buttons.toggleNotificationsReports
                ,{
                    scale: 'large'
                    ,arrowVisible: false
                    ,cls: 'user-menu-button'
                    ,iconCls: 'bgs32'
                    ,menu: []
                    ,name: 'userMenu'
                },{
                    xtype: 'tbspacer'
                    ,width: 10
                }

            ]
        });

        App.mainTabPanel = new Ext.TabPanel({
            tabWidth: 205
            ,minTabWidth: 100
            ,enableTabScroll: true
            ,resizeTabs: true
            ,activeTab: 0
            ,header: false
            ,region: 'center'
            ,plain: true
            ,bodyStyle: 'background-color: #FFF'
            ,componentCls: 'mainTabPanel'
            ,border: false
            ,defaults: {
                tabConfig: {
                    textAlign: 'left'
                }
            }
            ,items: []
        });

        App.mainStatusBar = new CB.widget.TaskBar({
                style:'border-top: 1px solid #aeaeae'
                ,height: 25
                ,trayItems: [
                    //{xtype: 'notewindowbutton'},
                    //'-',
                    {xtype: 'uploadwindowbutton'},
					'-',
                    {xtype: 'prawindowbutton'}
                ]
        });
    }

    /**
     * define main actions used in viewport
     * @return object
     */
    ,initActions: function() {
        this.actions = {
            toggleLeftRegion: new Ext.Action({
                tooltip: L.Back
                ,itemId: 'togglelr'
                ,pressed: true
                ,enableToggle: true
                ,iconCls: 'im-menu-negative'
                ,scale: 'large'
                ,scope: this
                ,handler: this.toggleLeftRegion
            })

            ,toggleFilterPanel: new Ext.Action({
                tooltip: L.Filter
                ,itemId: 'togglefp'
                ,enableToggle: true
                ,iconCls: 'im-filter-negative'
                ,scale: 'large'
                ,scope: this
                ,handler: this.onToggleFilterPanelClick
            })

            ,toggleNotificationsReports: new Ext.Action({
                tooltip: L.Reports
                ,itemId: 'toggleNotificationsReports'
                // ,enableToggle: true
                ,iconCls: 'im-assignment'
                ,cls: 'numbersButton'
                ,text: ''
                ,scale: 'large'
                ,scope: this
                ,handler: this.onToggleNotificationsReportsClick
            })

            ,toggleNotificationsView: new Ext.Action({
                tooltip: L.Notifications
                ,itemId: 'toggleNotifications'
                // ,enableToggle: true
		//,hidden:(!App.loginData.manage) //permissions for viewing reports/charts
                ,iconCls: 'im-notifications'
                ,cls: 'numbersButton'
                ,text: ''
                ,scale: 'large'
                ,scope: this
                ,handler: this.onToggleNotificationsViewClick
            })
        };

        return this.actions;
    }

    /**
     * define main buttons
     * @return object
     */
    ,initButtons: function() {
        this.initActions();

        this.buttons = {
            toggleLeftRegion: new Ext.Button(this.actions.toggleLeftRegion)
            ,toggleFilterPanel: new Ext.Button(this.actions.toggleFilterPanel)
            ,toggleNotificationsView: new Ext.Button(this.actions.toggleNotificationsView)
			,toggleNotificationsReports: new Ext.Button(this.actions.toggleNotificationsReports)
            ,create: new Ext.Button({
                qtip: L.New
                ,itemId: 'create'
                ,arrowVisible: false
                ,iconCls: 'im-create-negative'
                ,disabled: true
                ,scale: 'large'
                ,menuAlign: 'tl-tr'
                ,menu: [
                ]
                ,listeners: {
                    menushow: this.onButtonMenuShow
                }
            })
        };

        this.buttons.toggleLeftRegion.on(
            'afterrender'
            ,function(b, e) {
                b.toggle(App.mainLPanel.getCollapsed() === false);
            }
            ,this
        );

        App.on('notificationsUpdated', this.updateNotificationsCount, this);

        return this.actions;
    }

    ,toggleLeftRegion: function(b, e) {
        if(App.mainLPanel.collapsed) {  //Changed DS
            App.mainLPanel.expand();
        } else {
            App.mainLPanel.collapse();
        }
    }

    ,onToggleFilterPanelClick: function(b, e) {
	if (b.pressed)
	{
		App.mainLPanel.expand(); //Added DS
	}
        this.buttons.toggleFilterPanel.setPressed(b.pressed);

        App.mainLPanel.getLayout().setActiveItem(
            b.pressed
                ? 1
                : 0
        );
    }

    ,updateNotificationsCount: function(counts) {
        var text = (counts.unseen > 0)
            ? counts.unseen

            : '';

        this.buttons.toggleNotificationsView.setText(text);

        if(!this.originalDocTitle) {
            this.originalDocTitle = document.title;
        }

        document.title = (
            Ext.isEmpty(text)
                ? ''
                : '(' + text + ') '
            ) + this.originalDocTitle;
    }

    ,onToggleNotificationsViewClick: function(b, e) {
      var cpl = App.explorer.containersPanel.getLayout()//App.explorer.getLayout()
            ,hideNotifications = cpl.activeItem.isXType('CBNotificationsView');

        cpl.setActiveItem(
            hideNotifications
            ? Ext.valueFrom(App.explorer.previousActiveView, 0)
            : 1
        );
  	/*var VC = App.explorer;
		var AV = VC.getActiveView();
		if (AV.title != null)
		{
			if (AV.title !== 'Charts')
			{
				VC.onCardItemChangeClick({
				viewIndex: 2});
				VC.viewToolbar.setVisible(true);
			}
			else
			{
				VC.onCardItemChangeClick({
				viewIndex: 0});
			}
		}*/
    }

    ,onToggleNotificationsReportsClick: function(b, e) {
        var cpl = App.explorer.containersPanel.getLayout()
            ,hideNotifications = cpl.activeItem.isXType('CBNotificationsReports');

        cpl.setActiveItem(
            hideNotifications
            ? Ext.valueFrom(App.explorer.previousActiveView, 0)
            : 3
        );
    }

    ,onLogin: function(){
        /* adding menu items */
        var um, umIdx = App.mainTBar.items.findIndex( 'name', 'userMenu');
        if(umIdx > -1) {
            um = App.mainTBar.items.getAt(umIdx);
            um.setIcon(App.config.photoPath + App.loginData.id + '.jpg?32=' + CB.DB.usersStore.getPhotoParam(App.loginData.id));
        }

        // adding available languages to setting menu
        var langs = [];
        CB.DB.languages.each(
            function(r){
                langs.push({
                    text: r.get('name')
                    ,xtype: 'menucheckitem'
                    ,checked: (r.get('id') == App.loginData.language_id)
                    ,data:{id: r.get('id')}
                    ,scope: this
                    ,handler: this.setUserLanguage
                    ,group: 'language'
                });
            }
            ,this
        );


        // creating menu config for available themes
        var themes = [];
        CB.DB.themes.each(
            function(r){
                themes.push({
                    text: r.get('name')
                    ,xtype: 'menucheckitem'
                    ,checked: (r.get('id') == App.loginData.theme)
                    ,data:{id: r.get('id')}
                    ,scope: this
                    ,handler: this.setUserTheme
                    ,group: 'theme'
                });
            }
            ,this
        );

        um.menu.add(
            {
                text: L.Account
                // ,iconCls: 'icon-user-' + App.loginData.sex
                ,iconCls: 'icon-user-account'
                ,handler: function(){
                    App.windowManager.openWindow({
                        xtype: 'CBAccount'
                        ,id: 'accountWnd'
                    });
                }
            }
            ,'-'
            //,{                             //All Dumb Down Apprio
            //    text: L.NotifySettings
                // ,iconCls: 'i-settings'
                // ,itemId: 'notify-settings'
            //    ,scope: this
            //    ,handler: this.onNotifySettingsClick
            //}
            //,'-'
            ,{
                text: L.ApplicationFeedback
                ,iconCls: 'i-settings'
                ,handler: this.onApplicationFeedbackClick, scope: this
            }
            ,'-'
            ,{
                text: L.Exit
                ,iconCls: 'icon-exit'
                ,handler: this.logout, scope: this
            }
        );

        var managementItems = [];
        if(App.loginData.manage) {
            managementItems.push(
                {
                    text: L.Users
                    ,iconCls: 'icon-users'
                    ,handler: function(){
                        var w = new CB.VerifyPassword({
                            listeners:{
                                scope: this
                                ,beforeclose: function(cmp){
                                    if(cmp.success !== true) {
                                        cmp.destroy();
                                    } else {
                                        App.windowManager.openWindow({
                                            xtype: 'CBUsersGroups'
                                            ,id: 'usersGroupsWnd'
                                        });
                                    }
                                }
                            }
                        });
                        w.show();

                    }
                }
            );
        }

        if(App.loginData.admin) {
            managementItems.push(
                {
                    text: L.ReloadTemplates
                    ,iconCls: 'icon-templates'
                    ,handler: function(){
                        reloadTemplates();
                    }
                }
            );
        }

        if(!Ext.isEmpty(managementItems)) {
            managementItems.push('-');
            for (var i = managementItems.length - 1; i >= 0; i--) {
                um.menu.insert(2, managementItems[i]);
            }
        }

        /* end of adding menu items */

        this.populateMainMenu();
    }

    ,initCB: function(){
        if(CB.DB && CB.DB.templates && (CB.DB.templates.getCount() > 0)){
            var path = window.location.pathname.split('/');

                this.onLogin();
                App.DD = new CB.DD();

                Ext.Function.defer(this.checkUrlLocate, 1500);

                App.initialized = true;

                App.fireEvent('cbinit', this);

                // depress notifications button when view deactivated
                App.explorer.notificationsView.on(
                    'deactivate'
                    , function () {
                        this.buttons.toggleNotificationsView.toggle(false, true);
                    }
                    , this
                );
				App.windowManager.openWindow({
                        xtype: 'CBNoteUploader'
                        ,id: 'noteWnd'
                });

            if(path[3] == 'edit') {
                var w = App.windowManager.openObjectWindow({
                    template_id: path[4]
                    ,id: path[5]
                    ,mode: 'window'
                    ,view: 'edit'
                    ,maximized: true
                    ,maximizable: false
                    ,minimizable: false
                    ,resizable: false
                    ,closable: false
                    ,plain: true
                    ,border: false
                    // ,bodyBorder: false
                });
            }

        } else {

            Ext.Function.defer(this.initCB, 500, this);
        }
    }

    //check if a locate id is specified in url
    ,checkUrlLocate: function() {
        var locateId = String(window.location.search.split('locate=')[1]).split('&')[0];

        if(!Ext.isEmpty(locateId)) {
            // App.locateObject(locateId);
            App.controller.openObjectWindowById(locateId);
        }
    }
    ,onApplicationFeedbackClick: function(b, e) {
    	window.open('https://docs.google.com/forms/d/e/1FAIpQLSeKOGxkShixaLA_9ORpMTND0RkC0HxEuAyxtcLL0y9wjUg62A/viewform?c=0');
    }

    ,onLeftRibbonButtonClick: function(b, e) {
        App.openPath(b.path);
    }

    ,logout: function () {
        return Ext.Msg.show({
            buttons: Ext.Msg.YESNO
            , title: L.ExitConfirmation
            , msg: L.ExitConfirmationMessage
            , fn: function (btn, text) {
                if (btn === 'yes') {
                    CB_User.logout(function (r, e) {
                        if (r && (r.success === true)) {
                            App.confirmLeave = false;
                            window.location.reload();
                        } else if(r.msg) {
                            Ext.Msg.show({title: 'error', msg: r.msg});
                        }
                    });
                }
            }
        });
    }

    ,populateMainMenu: function(){
        App.mainTree = App.mainLPanel.add({
            layout: 'border'
            ,border: false
            ,scrollable: false
            ,items: [
                {
                    xtype: 'CBBrowserTree'
                    ,region: 'center'
                    ,border: false
                    ,bodyStyle: 'border: 0'
                    ,data: {
                        rootNode: App.config.rootNode
                    }
                }
                , {
                    region: 'south'
                    ,xtype: 'tabpanel'
                    ,tabPosition: 'bottom'
                    ,cls: 'left-bottom-tabpanel'
                    ,split: {
                        size: 3
                        ,collapsible: true
                        ,style: 'background-color: #dfe8f6'
                    }
                    ,stateful: true
                    ,stateId: 'lpsr'
                    ,collapseMode: 'mini'
                    ,border: false
                    ,bodyBorder: false
                    ,bodyStyle: 'background-color: transparent'
                    ,minHeight: 100
                    ,hidden: true
                    ,items: [
                        {
                            xtype: 'CBFavoritesPanel'
                        }
                    ]
                }
            ]
        }).items.getAt(0);

        App.Favorites = App.mainLPanel.down('CBFavoritesPanel');

        App.mainLPanel.getLayout().setActiveItem(0);

        App.mainFilterPanel = App.mainLPanel.add({
            xtype: 'CBFilterPanel'
            ,header: false
            ,border: false
            ,cls: 'x-panel-gray'
            ,tbar: [
                '->'
                ,{
                    iconCls: 'im-cancel'
                    ,itemId: 'close'
                    ,scale: 'medium'
                    ,scope: this
                    ,handler: function(b, e){
                        this.buttons.toggleFilterPanel.toggle(false);
                        this.onToggleFilterPanelClick(this.buttons.toggleFilterPanel, e);
                    }
                }
            ]
        });

        if(App.mainTree){
            App.mainTree.getRootNode().data.name = 'My CaseBox';
        }

        this.openDefaultExplorer();

        App.mainTabPanel.setActiveTab(0);
    }

    ,selectTreeRootNode: function() {
        if(App.mainTree && App.explorer) {
            if(App.mainTree.rendered) {
                var rn = App.mainTree.getRootNode();
                App.mainTree.selectPath('/'+ rn.get('nid'), 'nid', '/');
            }
        }
    }

    ,createObject: function(data, e){
        App.activateBrowserTab().editObject(data);
    }

    ,onAccordionLinkClick: function(p, animate){
        p = App.openUniqueTabbedWidget(p.link, null, {iconCls: p.iconCls, title: p.title});
        return false;
    }

    ,openCalendar: function(ev){
        if(ev && ev. stopPropagation) {
            ev.stopPropagation();
        }

        App.openUniqueTabbedWidget('CBCalendarPanel');
    }

    ,openDefaultExplorer: function(rootId){
        if(Ext.isEmpty(rootId) && App.mainTree) {
            rootId = Ext.valueFrom( App.mainTree.rootId, '/' );
        }

        if(!App.activateTab(App.mainTabPanel, 'explorer')) {
            App.explorer = App.addTab(
                App.mainTabPanel
                ,new CB.browser.ViewContainer({
                    rootId: rootId
                    ,data: {id: 'explorer' }
                    ,closable: false
                })
            );
        }
    }

    ,openPermissions: function(objectId) {
        if(isNaN(objectId)) {
            return;
        }

        App.windowManager.openWindow({
            xtype: 'CBSecurityWindow'
            ,id: 'opw' + objectId //objects permission window
            ,data: {
                id: objectId
            }
        });
    }

    ,onNotifySettingsClick: function(b, e) {
        var w = new CB.notifications.SettingsWindow();
        w.show();
    }

    ,setUserLanguage: function(b, e){
        var d = b.config.data;

        if(d.id == App.loginData.language_id) {
            return;
        }

        Ext.Msg.confirm(
            L.LanguageChange
            ,L.LanguageChangeMessage
            ,function(pb){
                if(pb === 'yes') {
                    CB_User.setLanguage(d.id, this.processSetUserOption, this);
                }
                if(d.ownerCt) {
                    d.ownerCt.items.each(
                        function(i){
                            i.setChecked(i.data.id == App.loginData.language_id);
                        }
                        ,this
                    );
                }
            }
            ,this
        );
    }

    ,setUserTheme: function(b, e){
        var d = b.config.data;

        if(d.id == App.loginData.theme) {
            return;
        }

        Ext.Msg.confirm(
            L.Theme
            ,L.ThemeChangeMessage
            ,function(pb){
                if(pb === 'yes') {
                    CB_User.setTheme(d.id, this.processSetUserOption, this);
                }
                if(d.ownerCt) {
                    d.ownerCt.items.each(
                        function(i){
                            i.setChecked(i.data.id == App.loginData.theme);
                        }
                        ,this
                    );
                }
            }
            ,this
        );
    }

    ,processSetUserOption: function(r, e){
        if(r && (r.success === true)) {
            App.confirmLeave = false;
            document.location.reload();
        } else {
            Ext.Msg.alert(L.Error, L.ErrorOccured);
        }
    }

    ,focusLastElement: function(){
        if(this.lastFocusedElement){
            this.lastFocusedElement.focus(500);
        }
    }

    ,onUsersChange: function(){
        CB.DB.usersStore.reload();
    }

    ,onDeleteObject: function(data){
        Ext.Msg.confirm(
            L.DeleteConfirmation
            ,L.DeleteConfirmationMessage + ' "' + Ext.valueFrom(data.title, data.name) +'"?'
            ,function(btn){
                if(btn === 'yes') {
                    CB_Browser['delete'](data.id, this.onProcessObjectsDeleted, this);
                }
            }
            ,this
        );

    }

    ,onProcessObjectsDeleted: function(r, e){
        if(!r || (r.success !== true)) {
            return;
        }
        if(!Ext.isEmpty(r.ids)) {
            this.fireEvent('objectsdeleted', r.ids, e);
        }
    }

    ,onFileUpload: function(data, e){
        if(e && e.stopEvent) {
            e.stopEvent();
        }

        if(!this.fileField) {
            this.fileField = document.createElement("INPUT");
            this.fileField.setAttribute("type", "file");
            this.fileField.setAttribute("multiple", "true");

            this.fileField.onchange = Ext.Function.bind(
                function(ev){
                    if(this.fileField.files.length > 0) {
                        App.addFilesToUploadQueue(this.fileField.files, this.fileField.data);
                    }
                }
                ,this
            );
        }
        this.fileField.data = data;

        this.fileField.value = null;
        this.fileField.click();
    }

    ,onFileUploaded: function(w, data){
        this.fireEvent('fileuploaded', {data: data});
    }

    ,onFilesDownload: function(ids, zipped, e){
        if(e) {
            e.stopPropagation();
        }

        if(zipped !== true){
            if(!Ext.isArray(ids)) {
                ids = String(ids).split(',');
            }

            Ext.each(
                ids
                ,function(id){
                    if(isNaN(id)) {
                        return false;
                    }
                    App.downloadFile(id);
                }
                ,this
            );
        } else {
            if(Ext.isArray(ids)) {
                ids = ids.join(',');
            }
            App.downloadFile(ids, true);
        }
    }

    ,onButtonMenuShow: function(btn, menu, eOpts) {
        menu.showBy(
            btn
            ,Ext.valueFrom(btn.menuAlign, 'tl-tr')
            ,[2, 0]
        );
    }
});
