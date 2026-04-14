import{$ as r}from"./dropzone-D_fpH5HM-v4.7.9.js";import"./just-extend-DZ8Smc-h-v4.7.9.js";class d{constructor(e){this.options=_.merge({type:null,multiple:!1,routePrefix:"file-manager",title:trans("media::media.file_manager.title")},e),this.events={},this.frame=this.getFrame(),this.appendModalToBody(),this.openFrame()}on(e,t){this.events[e]=t}getFrame(){let e=`${FleetCart.baseUrl}/admin/${this.options.routePrefix}?type=${this.options.type}&multiple=${this.options.multiple}`;return $(`<iframe class="file-manager-iframe" frameborder="0" src="${e}"></iframe>`)}appendModalToBody(){$(".media-picker-modal").length!==1&&($("body").append(this.getModal()),this.closeModalOnClickDismiss(),this.closeModalOnClickOutside())}openFrame(){this.showModal(),this.frame.on("load",()=>{this.selectMedia()})}showModal(){let e=$(".media-picker-modal").modal("show");this.setFrameHeight(),this.setFrameHeightOnWindowResize(),this.setModalTitle(e),this.setModalBody(e),this.closeModalOnEsc(e)}setFrameHeight(){this.frame.css("height",window.innerHeight*.8)}setFrameHeightOnWindowResize(){window.addEventListener("resize",()=>{this.setFrameHeight()})}setModalTitle(e){e.find(".modal-title").text(this.options.title)}setModalBody(e){e.find(".modal-body").html(this.frame)}closeModalOnEsc(e){$(document).on("keydown",t=>{t.key==="Escape"&&e.modal("hide")}),this.frame.on("load keydown",()=>{this.frame.contents().on("keydown",t=>{t.key==="Escape"&&e.modal("hide")})})}closeModalOnClickDismiss(){const e=$(".media-picker-modal");e.find('[data-dismiss="modal"]').on("click",()=>{e.modal("hide")})}closeModalOnClickOutside(){const e=$(".media-picker-modal");e.find(".modal-content").on("click",t=>{t.stopPropagation()}),e.on("click",()=>{e.modal("hide")})}selectMedia(){this.frame.contents().find(".table").on("click",".select-media",e=>{e.preventDefault(),this.events.select(e.currentTarget.dataset),this.options.multiple?$(e.currentTarget).attr("disabled",!0).html('<i class="fa fa-check" aria-hidden="true"></i>'):$(".media-picker-modal").modal("hide")})}getModal(){return`
            <div class="media-picker-modal modal fade" role="dialog">
                <div class="modal-dialog clearfix">
                    <div class="modal-content col-md-10 col-sm-11 clearfix">
                        <div class="row">
                            <div class="modal-header">
                                <a type="button" class="close" data-dismiss="modal">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M4.00073 11.9996L12 4.00037" stroke="#292D32" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path> 
                                        <path d="M12 11.9996L4.00073 4.00037" stroke="#292D32" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                    </svg>
                                </a>

                                <h5 class="modal-title"></h5>
                            </div>

                            <div class="modal-body"></div>
                        </div>
                    </div>
                </div>
            </div>
        `}}class n{constructor(){$(".image-picker").on("click",e=>{this.pickImage(e)}),this.sortable(),this.removeImageEventListener()}pickImage(e){let t=e.currentTarget.dataset.inputName,i=e.currentTarget.hasAttribute("data-multiple");new d({type:"image",multiple:i}).on("select",a=>{this.addImage(t,a,i,e.currentTarget)})}addImage(e,t,i,s){let a=this.getTemplate(e,t);if(i){let l=$(s).next(".multiple-images");l.find(".image-holder.placeholder").remove(),l.find(".image-list").append(a)}else $(s).siblings(".single-image").html(a)}getTemplate(e,t){return $(`
            <div class="image-holder">
                <img src="${t.path}">

                <button type="button" class="btn remove-image">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M6.00098 17.9995L17.9999 6.00053" stroke="#292D32" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M17.9999 17.9995L6.00098 6.00055" stroke="#292D32" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <input type="hidden" name="${e}" value="${t.id}">
            </div>
        `)}sortable(){let e=$(".image-list");e.length>0&&Sortable.create(e[0],{animation:150})}removeImageEventListener(){$(".image-holder-wrapper").on("click",".remove-image",e=>{e.preventDefault();let t=$(e.currentTarget).closest(".image-holder-wrapper");t.find(".image-holder").length===1&&t.html(this.getImagePlaceholder(e.currentTarget.dataset.inputName)),$(e.currentTarget).parent().remove()})}getImagePlaceholder(e){return`
            <div class="image-holder placeholder cursor-auto">
                <i class="fa fa-picture-o"></i>
                
                <input type="hidden" name="${e}">
            </div>
        `}}class m{constructor(){r.autoDiscover=!1,this.dropzone=new r(".dropzone",{url:`${FleetCart.baseUrl}/admin/media`,autoProcessQueue:!0,maxFilesize:FleetCart.maxFileSize}),this.dropzone.on("sending",this.sending),this.dropzone.on("success",this.success),this.dropzone.on("error",this.error)}sending(e,t){t.timeout=36e5,$(".alert-danger").remove()}success(){this.getUploadingFiles().length===0&&this.getQueuedFiles().length===0&&setTimeout(DataTable.reload,1e3,"#media-table .table")}error(e,t){$(".dz-progress").css("z-index",1),$(e.previewElement).find(".dz-error-message").text(t.message)}}window.MediaPicker=d;$(".image-picker").length!==0&&new n;$(".dropzone").length!==0&&new m;
