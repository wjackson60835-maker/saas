	createSwiper('fivetab1_1', 'fivetab2_1', {delay: 2500});

	function createSwiper(id1, id2, autoplay)
	{
		var clientWidth;
		var navSlideWidth;
		var mySwiper1 = new Swiper('#' + id1, {
			freeMode: true,
			autoplay: autoplay,
			disableOnInteraction: false,
			// slideToClickedSlide: true, //点击跟随滑动
			slidesPerView: 4, //一屏显示的个数
			touchRatio: 0,
			onTap: function () {

			},
			on: {
				init: function() {
					navSlideWidth = this.slides.eq(0).css('width'); //导航字数需要统一,每个导航宽度一致
					navSum = this.slides[this.slides.length - 1].offsetLeft //最后一个slide的位置

					clientWidth = parseInt(this.$wrapperEl.css('width')) //Nav的可视宽度
					navWidth = 0
					for (i = 0; i < this.slides.length; i++) {
						navWidth += parseInt(this.slides.eq(i).css('width'))
					}
				}
			}
		});
		mySwiper1.on('tap', function () {
			clickIndex = this.clickedIndex;
			mySwiper2.slideTo(clickIndex);
			mySwiper2.autoplay.stop();
		});


		var mySwiper2 = new Swiper('#' + id2, {
			autoplay: autoplay,
			disableOnInteraction: false,
			onSlideChangeStart: function () {
				// updateNavPosition()
			},
			on: {
				transitionStart: function() {
					activeIndex = this.activeIndex
					navActiveSlideLeft = mySwiper1.slides[activeIndex].offsetLeft //activeSlide距左边的距离
					mySwiper1.setTransition(0);
					mySwiper1.setTranslate(0);

					// if (navActiveSlideLeft < (clientWidth - parseInt(navSlideWidth)) / 2) {
					// 	mySwiper1.setTranslate(0)
					// } else if (navActiveSlideLeft > navWidth - (parseInt(navSlideWidth) + clientWidth) / 2) {
					// 	mySwiper1.setTranslate(clientWidth - navWidth)
					// } else {
					// 	mySwiper1.setTranslate((clientWidth - parseInt(navSlideWidth)) / 2 - navActiveSlideLeft)
					// }

					$('#' + id1 + ' .active-nav').removeClass('active-nav');
					mySwiper1.slides.eq(activeIndex).addClass('active-nav');
					mySwiper1.autoplay.stop();

				}
			}
		})

		function updateNavPosition() {
			$('#' + id1 + ' .active-nav').removeClass('active-nav')
			var activeNav = $('#' + id1 + ' .swiper-slide').eq(mySwiper2.activeIndex).addClass('active-nav');
			console.log(activeNav.index())
			if (!activeNav.hasClass('swiper-slide-visible')) {
				if (activeNav.index() > mySwiper1.activeIndex) {
					var thumbsPerNav = Math.floor(mySwiper1.width / activeNav.width()) - 1
					console.log(thumbsPerNav.index())
					mySwiper1.slideTo(activeNav.index() - thumbsPerNav)
				} else {
					mySwiper1.slideTo(activeNav.index())
				}
			}
		}

	}