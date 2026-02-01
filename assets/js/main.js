// Initialize Swiper for card slider
document.addEventListener('DOMContentLoaded', function() {
    const cardSwiper = new Swiper('.card-swiper', {
        // Optional parameters
        loop: true,
        autoplay: {
            delay: 3000,
            disableOnInteraction: false,
        },
        speed: 600,
        
        // Navigation arrows
        navigation: {
            nextEl: '.swiper-button-next',
            prevEl: '.swiper-button-prev',
        },
        
        // Pagination
        pagination: {
            el: '.swiper-pagination',
            clickable: true,
        },
        
        // Responsive breakpoints
        breakpoints: {
            // when window width is >= 320px
            320: {
                slidesPerView: 1,
                spaceBetween: 20
            },
            // when window width is >= 640px
            640: {
                slidesPerView: 2,
                spaceBetween: 30
            },
            // when window width is >= 768px
            768: {
                slidesPerView: 3,
                spaceBetween: 40
            }
        }
    });
});

