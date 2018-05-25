Ext.namespace('CB.notifications');

Ext.define('CB.notifications.Reports', {
    extend: 'Ext.Panel'

    ,alias: 'widget.CBNotificationsReports'

    ,border: false
    ,layout: 'fit'

    ,initComponent: function(){
		
		this.enableBubble('exportrecords');
        
		this.defineStore();
		
        this.rowsCombo = new Ext.form.ComboBox({
            xtype: 'combo'
            ,forceSelection: true
            ,editable: false
            ,width:'200px'
            ,triggerAction: 'all'
			,store: this.reports
			,queryMode: 'local'
			,displayField: 'name'
			,valueField: 'nid'
            ,emptyText: L.SelectReport
            ,labelWidth: 'auto'
            ,style: 'margin-right: 10px'
			,listeners:{
				 scope: this,
				 'select': this.onReportSelect
			}
        });
        
        
        this.teamsCombo = new Ext.form.ComboBox({
            xtype: 'combo'
            ,forceSelection: false
            ,editable: false
            ,triggerAction: 'all'
			,store: this.teamStore
			,queryMode: 'local'
			,displayField: 'name'
			,valueField: 'id'
            ,labelWidth: 'auto'
            ,style: 'margin-right: 10px'
			,hidden: true
			            ,listeners:{
				 scope: this,
				 'select': this.onReportSelect
            }      
        });        
		var myDate = new Date();
		var newDate = new Date(myDate.getTime() - (60*60*24*7*1000));
		this.startDate = new Ext.form.Date({
			xtype: 'datefield',
			fieldLabel: L.ReportFrom,
			labelStyle:'text-align:right',
			name: 'fromDate',
			itemId: 'fromDate',
			hidden: true,
			value: newDate,  // defaults to today		
			maxValue: new Date()  // limited to the current date or prior
			,listeners:{
				 scope: this,
				 'change': this.onReportSelect
            }      
		});
		
		this.endDate = new Ext.form.Date({
			xtype: 'datefield',
			fieldLabel: L.ReportTo,
			labelStyle:'text-align:right',
			name: 'endDate',
			itemId: 'endDate',
			hidden: true,
			value: new Date(),  // defaults to today		
			maxValue: new Date()  // limited to the current date or prior
            ,listeners:{
				 scope: this,
				 'change': this.onReportSelect
            }      			
		});

		
        this.coursesCombo = new Ext.form.ComboBox({
            xtype: 'combo'
            ,forceSelection: false
            ,editable: false
            ,triggerAction: 'all'
			,store: this.courseStore
			,queryMode: 'local'
			,displayField: 'name'
			,valueField: 'id'
			,hidden: true	
			,width:'250px'
            ,labelWidth: 'auto'
            ,style: 'margin-right: 10px'
            ,listeners:{
				 scope: this,
				 'select': this.onReportSelect
            }      
        });    		
		
		this.yesNoCombo = new Ext.form.ComboBox({
                     enableKeyEvents: true
                     ,triggerAction: 'all'
                     ,queryMode: 'local'
                     ,editable: false
                     ,hidden: true	
                     ,store: CB.DB.yesno
                     ,displayField: 'name'
                     ,valueField: 'id'
            ,listeners:{
				 scope: this,
				 'select': this.onReportSelect
            }      
        });
		
        //define actions
        this.actions = {
            reload: new Ext.Action({
                iconCls: 'im-refresh'
                ,itemId: 'reload'
                ,scale: 'medium'
                ,tooltip: L.Refresh
				,hidden: true	
                ,scope: this
                ,handler: this.onReloadClick
            })

            ,export: new Ext.Action({
                itemId: 'export'
                ,scale: 'medium'
                ,iconCls: 'im-download'
                ,scope: this
				,hidden: true				
				,tooltip: L.ExportReport
                ,handler: this.onExportClick
            })			

            ,print: new Ext.Action({
                itemId: 'print'
                ,scale: 'medium'
                ,iconCls: 'icon-print'
                ,scope: this
				,hidden: true				
				,tooltip: L.PrintReport
                ,handler: this.onPrintClick
            })			
			
			
            ,preview: new Ext.Action({
                itemId: 'preview'
                ,scale: 'medium'
                ,iconCls: 'im-preview'
                ,scope: this
                ,hidden: true
                ,handler: this.onPreviewClick
            })

            ,close: new Ext.Action({
                iconCls: 'im-cancel'
                ,itemId: 'close'
                ,scale: 'medium'
                ,scope: this
                ,handler: this.onCloseClick
            })
        };

        //define toolbar
        this.tbar = new Ext.Toolbar({
            border: false
            ,style: 'background: #ffffff'
            ,defaults: {
                scale: 'medium'
            }
            ,items: [
				this.rowsCombo	
				,this.teamsCombo
				,this.coursesCombo	
				,this.yesNoCombo	
				,this.startDate
				,this.endDate
                ,'->'
				,this.actions.export
                ,this.actions.print
				,this.actions.reload
                ,this.actions.preview
                ,this.actions.close
            ]
        });

		Ext.apply(this, {
            items: [{
				xtype: 'panel'
                ,cls: 'taC'
                ,bodyStyle: 'background-color: #ffffff'
                ,border: false
                ,scrollable: 'y'
                ,items: []
            }]
            ,listeners: {
                scope: this
                ,activate: this.onActivateEvent
            }
        });

        this.callParent(arguments);
    }
	
	,onReportSelect: function() {
         this.store.load({
             params: {
                 reportId: this.rowsCombo.value,
                 endDate: Ext.Date.format(this.endDate.value, 'Y-m-d'),
                 startDate: Ext.Date.format(this.startDate.value, 'Y-m-d'),
                 ynId: this.yesNoCombo.value,
                 end: Ext.Date.format(new Date(), 'm-d-Y')
            }
         });
	}
	
    ,changeReport: function(reportData) {
		this.startDate.hide();
		this.endDate.hide();
		this.teamsCombo.hide();
		this.coursesCombo.hide();
		this.yesNoCombo.hide();
        this.removeAll(true);	
        
            var columns = []
                ,fields= []
                ,recs = [];

			var totalCount = 0;
            //form columns array
			if (reportData)
			{
				Ext.iterate(
                 reportData.columns
                 ,function(k, v, o) {
					if (k == 'number')
					{
						this.startDate.show();
						this.endDate.show();
					}
					else if (v['title'] == 'Course')
					{
						this.coursesCombo.emptyText = 'Course';
						this.coursesCombo.applyEmptyText();
						this.coursesCombo.show();
       				 	this.coursesCombo.store.load({params:{scope: 2311, fieldId:1843, limit:300,rows:300}});
     					this.yesNoCombo.emptyText = 'Has Course';
						this.yesNoCombo.applyEmptyText();
						this.yesNoCombo.show(); 				 	
					}
                   columns.push({
							text: v['title']
							,width: v['width'] || 100
							,cls: 'fwB'
							,align: 'center'
							//,renderer: this.cellRenderer
							,renderer: (v['type'] == 'date')?Ext.util.Format.dateRenderer('m/d/Y'):this.cellRenderer
							,dataIndex: k
							,summaryType: v['summaryType']
							,summaryRenderer: function(value, summaryData, dataIndex) {
								return (v['summaryRenderer'])?eval(v['summaryRenderer']): '';
							}
			
						});
						fields.push({
                         name: k,
						 type: v['type'],
						 dateFormat:'c'
                     });
                 }
                 ,this
             );
			 	
			
				//adding grid
				var table = this.add({
					xtype: 'grid'
					,flex: 1
					,title: reportData.title || 'Report'
					,width: '99%'
					,padding: 10,
			dockedItems: [{
				dock: 'top',
				xtype: 'toolbar',
				title: L.TotalRecords,
				items: [{
					text: 'Total Records: ' + reportData.data.length,
					enableToggle: false,
					pressed: false
				}]
			}],
			features: [{
				  ftype:'cbGridViewGrouping'
					 ,disabled: false
					 ,groupHeaderTpl: [
						 '{columnName}: {name} ({rows.length} Item{[values.rows.length > 1 ? "s" : ""]})'
					 ]}]
						,store: Ext.create('Ext.data.JsonStore', {
							fields: fields
							,data: reportData.data
							,groupField: reportData.groupField
						})
						,columnLines: true
						,columns: columns
						,viewConfig: {
							stripeRows: true
						}
						,listeners: {
							 viewready: function(grid) {
								/*var totalCount = this.getStore().getCount();
								var summaryRow = this.view.el.down('tr.x-grid-row-summary');
								summaryRow.addCls('x-grid-header-ct');
								Ext.Array.each(summaryRow.query('td'), function(td,index) {
									var cell = Ext.get(td);
									if (index == 0)
									{
										cell.setHtml('<b>Total Records: '+totalCount + '</b>');	
									}
								});*/
							}
						}					
					});
			} //titles
		
        this.updateLayout();
    }	
	
    ,defineStore: function() {
		
		this.courseStore = new Ext.data.DirectStore({
			autoLoad: false //true
                     ,autoDestroy: true
                     ,restful: false
                     ,remoteSort: true
                     ,model: 'FieldObjects'
                    ,proxy: {
                         type: 'direct'
                         ,paramsAsHash: true
                         ,limitParam: 'rows'
                         ,api: {
							read: CB_Browser.getObjectsForField
				}
							,reader: {
          					type: 'json'
                    ,successProperty: 'success'
                            ,rootProperty: 'data'
                             ,messageProperty: 'msg'
                         }
                         ,listeners:{
                             load: function(proxy, obj, opt){ 								 
                                 for (var i = 0; i < obj.result.data.length; i++) {
                                     obj.result.data[i].date = date_ISO_to_local_date(obj.result.data[i].date);
									 }
                             }
                         }
                     }
                     ,sortInfo: {
                         field: 'name'
                         ,direction: 'ASC'
                     }
 
                     ,listeners: {
                         scope: this

                         ,load:  function(store, recs, options) {
							 							 
						var newOne = { id: null, name: '- Select Course -', template_id:1797 }; 			
                             Ext.each(
                                 recs
                                 ,function(r){
									 newOne = r;
                                     r.set('iconCls', getItemIcon(r.data));
                                 }
                                 ,this
                             );
						newOne.set('name', '- Select Course -');
						newOne.set('id', null);
						store.insert(0,newOne);
                         }
                     }
                 });		
		
		this.teamStore = new Ext.data.DirectStore({
			autoLoad: false //true
                     ,autoDestroy: true
                     ,restful: false
                     ,remoteSort: true
                     ,model: 'FieldObjects'
                    ,proxy: {
                         type: 'direct'
                         ,paramsAsHash: true
                         ,limitParam: 'rows'
                         ,api: {
							read: CB_Browser.getObjectsForField
				}
							,reader: {
          					type: 'json'
                    ,successProperty: 'success'
                            ,rootProperty: 'data'
                             ,messageProperty: 'msg'
                         }
                         ,listeners:{
                             load: function(proxy, obj, opt){
                                 for (var i = 0; i < obj.result.data.length; i++) {
                                     obj.result.data[i].date = date_ISO_to_local_date(obj.result.data[i].date);
									 }
                             }
                         }
                     }
                     ,sortInfo: {
                         field: 'name'
                         ,direction: 'ASC'
                     }
 
                     ,listeners: {
                         scope: this

                         ,load:  function(store, recs, options) {
						var newOne = { id: null, name: '- Select Team -', template_id:1797 }; 			
                             Ext.each(
                                 recs
                                 ,function(r){
									 newOne = r;
                                     r.set('iconCls', getItemIcon(r.data));
                                 }
                                 ,this
                             );
						newOne.set('name', '- Select Team -');
						newOne.set('id', null);
						store.insert(0,newOne);

                         }
                     }
                 });				
		
		this.reports = new Ext.data.DirectStore({
			autoLoad: false //true
                     ,autoDestroy: true
                     ,restful: false
                     ,remoteSort: true
                     ,pageSize: 250
                     ,model: 'FieldObjects'
                    ,proxy: {
                         type: 'direct'
                         ,paramsAsHash: true
                         ,limitParam: 'rows'
                         ,api: {
							read: CB_BrowserTree.getChildren
				}
							,reader: {
          					type: 'json'
                    ,successProperty: 'success'
                            ,rootProperty: 'data'
                             ,messageProperty: 'msg'
                         }
                         ,listeners:{
                             load: function(proxy, obj, opt){
                                 for (var i = 0; i < obj.result.data.length; i++) {
                                     obj.result.data[i].date = date_ISO_to_local_date(obj.result.data[i].date);
									 }
                             }
                         }
                     }
                     ,sortInfo: {
                         field: 'name'
                         ,direction: 'ASC'
                     }
 
                     ,listeners: {
                         scope: this

                         ,load:  function(store, recs, options) {
                             Ext.each(
                                 recs
                                 ,function(r){
                                     r.set('iconCls', getItemIcon(r.data));
                                 }
                                 ,this
                             );

                         }
                     }
                 });		
		
		
        this.store = new Ext.data.DirectStore({
            autoLoad: false
            ,autoDestroy: true
            ,extraParams: {}
            ,pageSize: 200
			,test:'hi'
            ,model: 'Notification'
            ,sorters: [{
                 property: 'action_id'
                 ,direction: 'DESC'
            }]
            ,proxy: new  Ext.data.DirectProxy({
                paramsAsHash: true
                ,directFn: CB_Notifications.getList
                ,reader: {
                    type: 'json'
                    ,successProperty: 'success'
                    ,idProperty: 'ids'
                    ,rootProperty: 'data'
                    ,messageProperty: 'msg'
                }
            })

            ,listeners: {
                scope: this
                ,load: this.onStoreLoad
            }
        });
    }

    ,onStoreLoad: function(store, records, successful, eOpts) {
        var rd = store.proxy.reader.rawData;

        if(rd && (rd.success === true)) {
			this.actions.export.show();
			this.actions.print.show();
			this.actions.reload.show();
			this.changeReport(rd);	
        }
    }

    ,getViewInfo: function() {
        return {
            path: '-1'
            ,pathtext: 'Reports'
        };
    }

    ,onActivateEvent: function() {
		
         this.reports.load({
             params: {
				 path: '1/2/90/2726',
				 from: 'tree',
				 fieldId: 2726, //Reports ID
                 end: Ext.Date.format(new Date(), 'm-d-Y')
            }
         });
    }

    ,onReloadClick: function(b, e) {
        this.store.reload();
		this.reports.reload();
    }

    ,onExportClick: function(b, e) {
		var  params = {
                 reportId: this.rowsCombo.value,
                 teamId: this.teamsCombo.value,
                 endDate: Ext.Date.format(this.endDate.value, 'Y-m-d'),
                 startDate: Ext.Date.format(this.startDate.value, 'Y-m-d'),				 
                 courseId: this.coursesCombo.value,
                 ynId: this.yesNoCombo.value,
                 end: Ext.Date.format(new Date(), 'm-d-Y'),
				 export: 1
            };
 
         delete params.lastQuery;
 
         params.from = 'report';
		 window.open('/c/' + App.config.coreName + '/get/?export=' + Ext.encode(params));
    }	
	
    ,onPrintClick: function(b, e) {
		var  params = {
                 reportId: this.rowsCombo.value,
                 teamId: this.teamsCombo.value,
                 courseId: this.coursesCombo.value,
                 endDate: Ext.Date.format(this.endDate.value, 'Y-m-d'),
                 startDate: Ext.Date.format(this.startDate.value, 'Y-m-d'),
                 ynId: this.yesNoCombo.value,
                 end: Ext.Date.format(new Date(), 'm-d-Y'),
				 export: 1
            };
 
         delete params.lastQuery;
 
         params.from = 'report';
		 window.open('/c/' + App.config.coreName + '/get/?pdf=' + Ext.encode(params));
    }	

    /**
     * handler for preview toolbar button
     * @param  button b
     * @param  evente
     * @return void
     */
    ,onPreviewClick: function(b, e) {
        App.explorer.objectPanel.expand();
        this.actions.preview.hide();
    }

    ,onCloseClick: function(b, e) {
        App.mainViewPort.onToggleNotificationsReportsClick(b, e);
    }

    ,onItemContextMenu: function(view, record, item, index, e, eOpts) {
        e.stopEvent();
        if(Ext.isEmpty(this.contextMenu)){
            this.contextMenu = new Ext.menu.Menu({
                items: [
                    this.actions.markAsUnread
                ]
            });

        }
        this.contextMenu.node = record;

        this.contextMenu.showAt(e.getXY());
    }
});