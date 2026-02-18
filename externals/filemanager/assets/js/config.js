(function(angular) {
    "use strict";
    function getCookie(cname) {
        var name = cname + "=";
        var decodedCookie = decodeURIComponent(document.cookie);
        var ca = decodedCookie.split(';');
        for(var i = 0; i <ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') {
                c = c.substring(1);
            }
            if (c.indexOf(name) == 0) {
                return c.substring(name.length, c.length);
            }
        }
        return "";
    }
    if(getCookie("isDhm") === "0") {
        angular.module('FileManagerApp').constant("$config", {
            appName: "Administrador de archivos",
            defaultLang: "es",

            listUrl: "/hosting/filemanager/listdirectory",
            uploadUrl: "/hosting/filemanager/upload",

            renameUrl: "/hosting/filemanager/item/rename",
            copyUrl: "/hosting/filemanager/item/copy",
            removeUrl: "/hosting/filemanager/item/remove",
            editUrl: "/hosting/filemanager/item/edit",
            getContentUrl: "/hosting/filemanager/item/content/get",
            createFolderUrl: "/hosting/filemanager/item/folder/create",
            downloadFileUrl: "/hosting/filemanager/item/download",
            compressUrl: "/hosting/filemanager/item/compress",
            extractUrl: "/hosting/filemanager/item/extract",
            permissionsUrl: "/hosting/filemanager/item/permissions/set",

            enablePermissionsModule: true,
            enablePermissionsRecursive: false,
            enableCompressChooseName: false,

            isEditableFilePattern: '\\.(txt|html|phtml|htm|aspx|asp|ini|pl|py|md|php|css|js|log|htaccess|htpasswd|json|config)$',
            isImageFilePattern: '\\.(jpg|jpeg|gif|bmp|png|svg|tiff)$',
            isExtractableFilePattern: '\\.(zip|gz|tar|rar|gzip)$'
        });
    } else {
        angular.module('FileManagerApp').constant("$config", {
            appName: "Administrador de archivos Skeleton",
            defaultLang: "es",
    
            listUrl: "/dhm/filemanager/listdirectory",
            uploadUrl: "/dhm/filemanager/upload",
    
            renameUrl: "/dhm/filemanager/item/rename",
            copyUrl: "/dhm/filemanager/item/copy",
            removeUrl: "/dhm/filemanager/item/remove",
            editUrl: "/dhm/filemanager/item/edit",
            getContentUrl: "/dhm/filemanager/item/content/get",
            createFolderUrl: "/dhm/filemanager/item/folder/create",
            downloadFileUrl: "/dhm/filemanager/item/download",
            compressUrl: "/dhm/filemanager/item/compress",
            extractUrl: "/dhm/filemanager/item/extract",
            permissionsUrl: "/dhm/filemanager/item/permissions/set",
    
            enablePermissionsModule: true,
            enablePermissionsRecursive: false,
            enableCompressChooseName: false,
    
            isEditableFilePattern: '\\.(txt|html|phtml|htm|aspx|asp|ini|pl|py|md|php|css|js|log|htaccess|htpasswd|json|config)$',
            isImageFilePattern: '\\.(jpg|jpeg|gif|bmp|png|svg|tiff)$',
            isExtractableFilePattern: '\\.(zip|gz|tar|rar|gzip)$'
        });
    }
})(angular);