layui.use(['element','upload','laydate','form'], function(){
  var element = layui.element;
  var upload = layui.upload;
  var laydate = layui.laydate;
  var form = layui.form;
  
  //获取hash来切换选项卡，假设当前地址的hash为lay-id对应的值
  var layid = location.hash.replace(/^#tab=/, '');
  element.tabChange('tab', layid); //假设当前地址为：http://a.com#test1=222，那么选项卡会自动切换到“发送消息”这一项
  
  //监听Tab切换，以改变地址hash值
  element.on('tab(tab)', function(){
	var clayid=this.getAttribute('lay-id');
	if(clayid){
		location.hash = 'tab='+ clayid;
		$('.page').find('a').each(function(index,element){//避免tab翻页问题
			var url=$(this).attr('href');
			if(url.indexOf('tab=')==-1){
				$(this).attr('href', url+'#tab='+ clayid);
			}else{
				$(this).attr('href', url.replace(/tab=[\w]+/, 'tab='+ clayid));
			}
        });
	}
  });
  
  //跳转
	form.on('select(tourl)', function(data){
		window.location.href= data.value;
	}); 

  
  //提示
  $(".tips").on("mouseover",function(){
	layer.tips($(this).data('content'), this);
  }) 
 
  //用户登录验证
  form.on('submit(login-submit)', function(data){
  	var form = $("#dologin");
    var url = form.attr('action');
    var username = form.find("#username").val();
    var password = form.find("#password").val();
    var checkcode = form.find("#checkcode").val();
    var formcheck = form.find("#formcheck").val();
    
	$.ajax({
	  type: 'POST',
	  url: url,
	  dataType: 'json',
	  data: {
            username: username,
            password: password,
            checkcode: checkcode,
            formcheck: formcheck
       },
	  success: function (response, status) {
			if (response.code == 1) {
				layer.msg("登录成功！", {icon: 1});
				window.location.href = response.data;
			} else {
				form.find("#checkcode").val("");
				$('#codeimg').click();//更新验证码
				layer.msg("登录失败：" + response.data, {icon: 5});
			} 
      },
      error:function(xhr,status,error){
    	  layer.msg("登录请求发生错误!", {icon: 5});
    	  $('#note').html('登录请求发生错误，您可按照如下方式排查：<br>1、试着删除根目录下runtime目录，刷新页面重试;<br>2、检查系统会话文件存储目录是否具有写入权限；<br>3、检查服务器环境pathinfo及伪静态规则配置；');
      }
	});
    return false;
  });
  
  
  var sitedir=$('#sitedir').data('sitedir');
  var uploadurl = $("#preurl").data('preurl')+'/index/upload';
  
  //执行单图片实例
  var uploadInst = upload.render({
	elem: '.upload' //绑定元素
	,url: uploadurl //上传接口
	,field: 'upload' //字段名称
	,multiple: false //多文件上传
	,accept: 'images' //接收文件类型 images（图片）、file（所有文件）、video（视频）、audio（音频）
	,acceptMime: 'image/*'
    ,before: function(obj){ 
       //判断是否需要加水印
       if($(this.item).hasClass('watermark')){
	  	 uploadInst.config.url=uploadurl+'/watermark/1';//改变URL
	   }
	   layer.load(); //上传loading
	}
	,done: function(res){
	   var item = this.item;
	   var des=$(item).data('des');
	   layer.closeAll('loading'); //关闭loading
	   if(res.code==1){
		   $('#'+des).val(res.data[0]); 
		   $('#'+des+'_box').html("<dl><dt><img src='"+sitedir+res.data[0]+"' data-url='"+res.data[0]+"' ></dt><dd>删除</dd></dl>"); 
		   layer.msg('上传成功！'); 
	   }else{
		   layer.msg('上传失败：'+res.data); 
	   }
	}
	,error: function(){
		layer.closeAll('loading'); //关闭loading
		layer.msg('上传发生错误!'); 
	}
  });
  
   //执行多图片上传实例
  var files='';
  var html='';
  var html2='';
  var uploadsInst = upload.render({
	elem: '.uploads' //绑定元素
	,url: uploadurl //上传接口
	,field: 'upload' //字段名称
	,multiple: true//多文件上传
	,accept: 'images' //接收文件类型 images（图片）、file（所有文件）、video（视频）、audio（音频）
	,acceptMime: 'image/*'
	,before: function(obj){ 
	   //判断是否需要加水印
       if($(this.item).hasClass('watermark')){
	  	 uploadsInst.config.url=uploadurl+'/watermark/1';//改变URL
	   }
	   layer.load(); //上传loading
	}
	,done: function(res){
	   if(res.code==1){
		   if(files){
			   files+=','+res.data[0];
		   }else{
			   files+=res.data[0];
		   }
		   html += "<dl><dt><img src='"+sitedir+res.data[0]+"' data-url='"+res.data[0]+"'></dt><dd>删除</dd>" +
		   		"<dt><input type='text' name='picstitle[]' style='width:95%' /></dt>"+		
		   		"</dl>";
		   html2 += "<dl><dt><img src='"+sitedir+res.data[0]+"' data-url='"+res.data[0]+"'></dt><dd>删除</dd>" +	"</dl>";
	   }else{
		   layer.msg('有文件上传失败：'+res.data); 
	   } 
	}
  	,allDone: function(obj){
  		var item = this.item;
  	    var des=$(item).data('des');
  	    
  	    layer.closeAll('loading'); //关闭loading
	    if(files!=''){
	       if($('#'+des).val()){
	    	   $('#'+des).val($('#'+des).val()+','+files); 
	       }else{
	    	   $('#'+des).val(files); 
	       }
	       if(des=='pics'){
	    	   $('#'+des+'_box').append(html); 
	       }else{
	    	   $('#'+des+'_box').append(html2); 
	       }
	 	   layer.msg('成功上传'+obj.successful+'个文件！'); 
	 	   files='';
	 	   html='';
	 	   html2='';
	    }else{
	 	   layer.msg('全部上传失败！'); 
	    }
	    
	 }
	,error: function(){
		layer.closeAll('loading'); //关闭loading
		layer.msg('上传发生错误！'); 
	}
  });
	
  //图片页面删除：先删节点再按剩余缩略图重建隐藏域，避免 data-url 与 input 值不一致时删不干净
  $('.pic').on('click', 'dl dd', function () {
	  var $dl = $(this).closest('dl');
	  var $pic = $(this).closest('.pic');
	  var id = $pic.attr('id');
	  if (!id || id.indexOf('_box') === -1) {
		  return;
	  }
	  var input = $('#' + id.replace('_box', ''));
	  $dl.remove();
	  var parts = [];
	  $pic.find('dl dt img').each(function () {
		  var u = $(this).attr('data-url');
		  if (u !== undefined && u !== null && String(u) !== '') {
			  parts.push(String(u));
		  }
	  });
	  input.val(parts.join(','));
  });

  // 缩略图等：一键清空上传框与预览
  $(document).on('click', '.js-clear-upload', function () {
	  var des = $(this).data('des');
	  if (!des) {
		  return;
	  }
	  $('#' + des).val('');
	  $('#' + des + '_box').empty();
	  layer.msg('已清除');
  });
  
  //执行附件上传实例
  var uploadFileInst = upload.render({
	elem: '.file' //绑定元素
	,url: uploadurl //上传接口
	,field: 'upload' //字段名称
	,multiple: false //多文件上传
	,accept: 'file' //接收文件类型 images（图片）、file（所有文件）、video（视频）、audio（音频）
	,before: function(obj){ 
		layer.load(); //上传loading
	}
	,done: function(res){
	   var item = this.item;
	   var des=$(item).data('des');
	   layer.closeAll('loading'); //关闭loading
	   if(res.code==1){
		   $('#'+des).val(res.data[0]); 
		   layer.msg('上传成功！'); 
	   }else{
		   layer.msg('上传失败：'+res.data); 
	   }
	}
	,error: function(){
		layer.closeAll('loading'); //关闭loading
		layer.msg('上传发生错误！'); 
	}
  });
  
  //使用多日期控件
  useLayDateMultiple('year','year');
  useLayDateMultiple('month','month');
  useLayDateMultiple('time','time');
  useLayDateMultiple('date','date');
  useLayDateMultiple('datetime','datetime');

  //选择模型切换模板
   form.on('select(model)', function(data){
	  var elem = data.elem;
	  var type = $(elem).find("option:selected").data('type');
	  var listtpl = $(elem).find("option:selected").data('listtpl');
	  var contenttpl = $(elem).find("option:selected").data('contenttpl');
	  
	  $(elem).parents('form').find("#type").val(type);
	  addOptionValue("listtpl",listtpl,listtpl);
	  addOptionValue("contenttpl",contenttpl,contenttpl);
	  $(elem).parents('form').find("#listtpl").val(listtpl);
	  $(elem).parents('form').find("#contenttpl").val(contenttpl);
	  form.render(null, 'sort'); 
	}); 
   
});

//日期控件函数
function useLayDateMultiple(cls,type) {
	layui.use('laydate', function() {
		var laydate = layui.laydate;
		lay('.' + cls).each(function() {
			laydate.render({
				elem : this,
				type : type,
			});
		});
	});
} 
