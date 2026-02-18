define(['knockout','panelalert' , 'ko.mapping', 'fzPaginatorAjax'], function(ko, PanelAlert , mapping, fzPaginatorAjax) {

    return function alertsgraphVM() {
        'use strict';

        var self = this;

        this.inprocess = ko.observable(0);
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new PanelAlert());
        this.panelAlertUsers = ko.observableArray([]);
        this.details = ko.observable();
        this.leftInfo = ko.computed(function() {
                            return "Alertas: "+self.data().length;
                        });

        ko.mapping = mapping;

        this.statuses = [
            {label: 'NEW', actionLabel: 'NEW', value: 'NEW'},
            {label: 'ASSIGNED', actionLabel: 'ASSIGNED', value: 'ASSIGNED'},
            {label: 'IN PROGRESS', actionLabel: 'IN PROGRESS', value: 'IN PROGRESS'},
            {label: 'FIXED', actionLabel: 'FIXED', value: 'FIXED'},
            {label: 'CANCELED', actionLabel: 'CANCELED', value: 'CANCELED'}
        ];
        
        mediator.installTo(this);

        this.subscribe('refreshPanelAlertListAjax', function() {
            this.listPaginated();
        });
        this.loadDetail = function (){
            self.leftInfo();
        };
        this.listPaginated = function() {
            self.pagination.ajaxViewModelListing(this, PanelAlert, "/dashboard/panelalert/list");
            //, false, FerozoDashboard.alertsgraphVM().loadDetail()
        };
        this.pagination = new fzPaginatorAjax(function() {
            self.listPaginated();
        });
        this.init = function() {
            'use strict';
             // self.loadPanelAlertUsers();
              $.postJSON("/dashboard/panelalert/graph/get", function(data) {
                if (data.result) {
                    self.initGraph(data.result);
                    //self.panelAlertUsers(data.result);
                }
             });
            mediator.publish('refreshPanelAlertListAjax');

        };
        this.initGraph =  function(json) {
                 var labelType, useGradients, nativeTextSupport, animate;
            (function() {
              var ua = navigator.userAgent,
                  iStuff = ua.match(/iPhone/i) || ua.match(/iPad/i),
                  typeOfCanvas = typeof HTMLCanvasElement,
                  nativeCanvasSupport = (typeOfCanvas == 'object' || typeOfCanvas == 'function'),
                  textSupport = nativeCanvasSupport 
                    && (typeof document.createElement('canvas').getContext('2d').fillText == 'function');
              //I'm setting this based on the fact that ExCanvas provides text support for IE
              //and that as of today iPhone/iPad current text support is lame
              labelType = (!nativeCanvasSupport || (textSupport && !iStuff))? 'Native' : 'HTML';
              nativeTextSupport = labelType == 'Native';
              useGradients = nativeCanvasSupport;
              animate = !(iStuff || !nativeCanvasSupport);
            })();

            var Log = {
              elem: false,
              write: function(text){
                if (!this.elem) 
                  this.elem = document.getElementById('log');
                this.elem.innerHTML = text;
                this.elem.style.left = (500 - this.elem.offsetWidth / 2) + 'px';
              }
            };

             //init RGraph
             var rgraph = new $jit.RGraph({
                 //Where to append the visualization
                 injectInto: 'infovis',
                 duration: 300,
                 //Optional: create a background canvas that plots
                 //concentric circles.
                 background: {
                   CanvasStyles: {
                     strokeStyle: '#555'
                   }
                 },
                 //Add navigation capabilities:
                 //zooming by scrolling and panning.
                 Navigation: {
                   enable: true,
                   panning: true,
                   zooming: 30
                 },
                 //Set Node and Edge styles.
                 Node: {
                     color: '#ddeeff'
                 },

                 Edge: {
                   color: '#C17878',
                   lineWidth:1.5
                 },

                 onBeforeCompute: function(node){
                     Log.write("centering " + node.name + "...");
                     //Add the relation list in the right column.
                     //This list is taken from the data property of each JSON node.
                     
                     //$jit.id('inner-details').innerHTML = node.data.relation;
                     //FerozoDashboard.alertsgraphVM().details(node.data.relation);
                     FerozoDashboard.alertsgraphVM().details(node.data.requestIds);
                     
                 },

                 //Add the name of the node in the correponding label
                 //and a click handler to move the graph.
                 //This method is called once, on label creation.
                 onCreateLabel: function(domElement, node){
                     domElement.innerHTML = node.name;
                     domElement.onclick = function(){
                         rgraph.onClick(node.id, {
                             onComplete: function() {
                                 Log.write("done");
                             }
                         });
                     };
                 },
                 //Change some label dom properties.
                 //This method is called each time a label is plotted.
                 onPlaceLabel: function(domElement, node){
                     var style = domElement.style;
                     style.display = '';
                     style.cursor = 'pointer';

                     if (node._depth <= 1) {
                         style.fontSize = "0.8em";
                         style.color = "#ccc";

                     } else if(node._depth == 2){
                         style.fontSize = "0.7em";
                         style.color = "#494949";

                     } else {
                         style.display = 'none';
                     }

                     var left = parseInt(style.left);
                     var w = domElement.offsetWidth;
                     style.left = (left - w / 2) + 'px';
                 }
             });
             //load JSON data
             rgraph.loadJSON(json);
             //trigger small animation
             rgraph.graph.eachNode(function(n) {
               var pos = n.getPos();
               pos.setc(-200, -200);
             });
             rgraph.compute('end');
             rgraph.fx.animate({
               modes:['polar'],
               duration: 400
             });
             //end
             //append information about the root relations in the right column
             $jit.id('_inner-details').innerHTML = rgraph.graph.getNode(rgraph.root).data.relation;

        }
        
        this.editAlert = function(requestId, event) {
            var panelAlert = ko.utils.arrayFilter(self.data(), function(data) {
                return data.requestId() == requestId;
            });
            FerozoDashboard.alertsgraphVM().temp(panelAlert[0]);
            $("#edit").modal('show');
        };
    };
});