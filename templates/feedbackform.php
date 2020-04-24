<!doctype html>
<html lang="en">
	<head>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
		<title>Rate the support</title>
		<!-- Latest compiled and minified CSS -->
		<link rel="stylesheet" href="<?php echo BBP_PLUGIN_URL;?>/templates/css/bootstrap.min.css">
		<link rel="stylesheet" href="<?php echo BBP_PLUGIN_URL;?>/templates/css/fontawesome.min.css">
		<link rel="stylesheet" href="<?php echo BBP_PLUGIN_URL;?>/templates/css/style.css">
	</head>
	<body>
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-md-7">
					<div class="card text-center mt-4">
						<form method="POST" action="" class="p-4">
							<h3 class="mt-4 mb-4">Thanks for your rating!</h3>
							<div class="row justify-content-center smileys mb-4">
								<?php $rating = get_query_var('rate');?>
								<label class="col-3 happy <?php echo ($rating==3 ? 'alert-success' : '');?>">
									<input type="radio" name="newrate" value="3" <?php checked($rating, 3);?>>
									<i class="far fa-smile"></i>
									<span>Good</span>
								</label>
								<label class="col-3 neutral <?php echo ($rating==2 ? 'alert-success' : '');?>">
									<input type="radio" name="newrate" value="2" <?php checked($rating, 2);?>>
									<i class="far fa-meh"></i>
									<span>Okay</span>
								</label>
								<label class="col-3 sad <?php echo ($rating==1 ? 'alert-success' : '');?>">
									<input type="radio" name="newrate" value="1" <?php checked($rating, 1);?>>
									<i class="far fa-frown"></i>
									<span>Not Good</span>
								</label>
							</div>
							<div class="form-group mb-4">
								<label class="col-12 control-label">Would you like to share any other comments?</label>
								<div class="col-12">
									<textarea class="form-control" name="feedback" rows="4" required></textarea>
								</div>
							</div>
							<div class="form-group text-center">
								<?php wp_nonce_field('topic_survey', 'topic_survey_nonce_field'); ?>
								<button type="submit" class="btn btn-primary btn-lg" style="min-width: 150px;">Send</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
		<script type="text/javascript">
			const LABELS = document.querySelectorAll('.smileys label');
			const INPUTS = document.querySelectorAll('.smileys label input');
			function hasClass(el, className){
				if (el.classList){
					return el.classList.contains(className);
				}
				return !!el.className.match(new RegExp('(\\s|^)' + className + '(\\s|$)'));
			}

			function addClass(el, className) {
				if (el.classList) {
					el.classList.add(className)
				} else if (!hasClass(el, className)){
					el.className += " " + className;
				}
			}
			function removeClass(el, className) {
				if (el.classList){
					el.classList.remove(className)
				} else if (hasClass(el, className)) {
					var reg = new RegExp('(\\s|^)' + className + '(\\s|$)');
					el.className = el.className.replace(reg, ' ');
				}
			}
			function updateValue(e, el) {
				//document.querySelector('#result').innerHTML = e.target.value;
				LABELS.forEach(ell => removeClass(ell, 'alert-success'));
				var x = el.parentElement;
				if (!hasClass(el, 'alert-success')) {
					addClass(x, 'alert-success');
				}
			}
			INPUTS.forEach(el => el.addEventListener('click', (e) => updateValue(e, el)));
		</script>
	</body>
</html>