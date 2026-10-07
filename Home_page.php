<?php
// Load background images from JSON file
$backgroundFilePath = 'assets/content/background_images.json';
$backgroundImages = [];
if (file_exists($backgroundFilePath)) {
    $backgroundData = json_decode(file_get_contents($backgroundFilePath), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $backgroundImages = $backgroundData['background_images'];
    }
}

// Select a random background image
$selectedBackground = !empty($backgroundImages) ? $backgroundImages[array_rand($backgroundImages)] : 'image/background.jpg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMU SRMS - Service Request Management System</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: rgb(85, 75, 189);
            --secondary: #1a1666;
            --accent: #ff4444;
            --light: #f5f5f5;
            --dark: #222;
            --gray: #999;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Ubuntu', sans-serif;
        }
        
        body {
            background-color: var(--light);
            color: var(--dark);
            line-height: 1.6;
            transition: background-color 0.3s, color 0.3s;
        }
        
        /* Preloader */
        #preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--primary);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .loader {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Navigation Bar */
        .navbar {
            background-color: var(--primary);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }
        
        .logo {
            display: flex;
            align-items: center;
        }
        
        .logo img {
            height: 40px;
            margin-right: 10px;
        }
        
        .logo h1 {
            color: white;
            font-size: 1.5rem;
        }
        
        .nav-links {
            display: flex;
            list-style: none;
            transition: 0.3s;
        }
        
        .nav-links li {
            margin-left: 2rem;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 4px;
        }
        
        .nav-links a:hover {
            background-color: rgba(255,255,255,0.2);
        }
        
        .nav-links .login-btn {
            background-color: var(--accent);
        }
        
        .nav-links .login-btn:hover {
            background-color: #e03e3e;
        }
        
        /* Mobile Menu */
        .hamburger {
            display: none;
            cursor: pointer;
            padding: 10px;
        }
        .hamburger span {
            display: block;
            width: 25px;
            height: 3px;
            background: white;
            margin: 5px 0;
            transition: 0.4s;
        }
        .hamburger.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-5px, 6px);
        }
        .hamburger.active span:nth-child(2) {
            opacity: 0;
        }
        .hamburger.active span:nth-child(3) {
            transform: rotate(45deg) translate(-5px, -6px);
        }
        
        /* Hero Section */
        .hero {
            height: 100vh;
            display: flex;
            align-items: center;
            padding: 0 10%;
            margin-top: 80px;
            background: linear-gradient(rgba(42,33,133,0.8), rgba(42,33,133,0.8)), 
                        url('<?php echo $selectedBackground; ?>') no-repeat center center/cover;
            color: white;
        }
        
        .hero-content {
            max-width: 600px;
            animation: fadeInUp 1s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .hero h2 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
        }
        
        .cta-buttons {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background-color: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #e03e3e;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(255, 68, 68, 0.3);
        }
        
        .btn-secondary {
            background-color: transparent;
            color: white;
            border: 2px solid white;
        }
        
        .btn-secondary:hover {
            background-color: rgba(255,255,255,0.1);
            transform: translateY(-3px);
        }
        
        /* Stats Section */
        .stats {
            background: var(--secondary);
            color: white;
            padding: 4rem 10%;
            text-align: center;
        }
        .stats-container {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 2rem;
        }
        .stat-item {
            flex: 1;
            min-width: 200px;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s, transform 0.5s;
        }
        .stat-item.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .stat-item i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--accent);
        }
        .stat-item h3 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        /* Features Section */
        .features {
            padding: 5rem 10%;
            background-color: white;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .section-title h2 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .section-title p {
            color: var(--gray);
            max-width: 700px;
            margin: 0 auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background-color: var(--light);
            padding: 2rem;
            border-radius: 8px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s, transform 0.5s, box-shadow 0.3s;
        }
        
        .feature-card.visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        .feature-card:hover {
            transform: translateY(-10px) !important;
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
        }
        
        .feature-icon {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .feature-card h3 {
            margin-bottom: 1rem;
            color: var(--primary);
        }
        
        /* About Section */
        .about {
            padding: 5rem 10%;
            background-color: var(--light);
        }
        
        .about-content {
            display: flex;
            align-items: center;
            gap: 3rem;
        }
        
        .about-text {
            flex: 1;
        }
        
        .about-image {
            flex: 1;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            transform: perspective(1000px) rotateY(0deg);
            transition: transform 0.5s ease;
        }
        
        .about-image:hover {
            transform: perspective(1000px) rotateY(5deg);
        }
        
        .about-image img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        /* FAQ Section */
        .faq {
            padding: 5rem 10%;
            background-color: white;
        }
        
        .accordion {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .accordion-item {
            margin-bottom: 1rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease;
        }
        
        .accordion-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .accordion-header {
            background-color: var(--primary);
            color: white;
            padding: 1rem 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.3s ease;
        }
        
        .accordion-header:hover {
            background-color: var(--secondary);
        }
        
        .accordion-content {
            padding: 1rem 1.5rem;
            background-color: var(--light);
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .accordion-item.active .accordion-content {
            display: block;
        }
        
        /* Testimonials */
        .testimonials {
            padding: 5rem 10%;
            background: var(--light);
        }
        .testimonial-slider {
            max-width: 800px;
            margin: 2rem auto;
            position: relative;
        }
        .testimonial {
            display: none;
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .testimonial.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        .author img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin: 1rem auto;
        }
        
        /* Contact Section */
        .contact {
            padding: 5rem 10%;
            background-color: var(--light);
        }
        
        .contact-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 3rem;
        }
        
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .contact-icon {
            font-size: 1.5rem;
            color: var(--primary);
        }
        
        .contact-form {
            background-color: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--gray);
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .form-group textarea {
            min-height: 150px;
        }
        
        .submit-btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.3s ease;
            width: 100%;
        }
        
        .submit-btn:hover {
            background-color: var(--secondary);
        }
        
        /* Footer */
        footer {
            background-color: var(--primary);
            color: white;
            padding: 2rem 10%;
            text-align: center;
        }
        
        .footer-content {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .footer-column {
            flex: 1;
            min-width: 200px;
        }
        
        .footer-column h3 {
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 0.5rem;
        }
        
        .footer-links a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--accent);
        }
        
        .social-links {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1rem;
        }
        
        .social-links a {
            color: white;
            font-size: 1.5rem;
            transition: color 0.3s ease;
        }
        
        .social-links a:hover {
            color: var(--accent);
        }
        
        .copyright {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 1rem;
            margin-top: 1rem;
        }
        
        /* Dark Mode */
        .dark-mode {
            --light: #222;
            --dark: #f5f5f5;
            --gray: #777;
            background-color: #111;
        }
        .dark-mode .feature-card,
        .dark-mode .testimonial,
        .dark-mode .contact-form,
        .dark-mode .accordion-content {
            background-color: #333;
            color: white;
        }
        .dark-mode .section-title p {
            color: #aaa;
        }
        
        /* Dark Mode Toggle */
        #darkModeToggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.2rem;
        }
        
        /* Back to Top */
        .back-to-top {
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: var(--accent);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            z-index: 999;
        }
        .back-to-top.visible {
            opacity: 1;
        }
        .back-to-top:hover {
            transform: translateY(-5px);
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .hamburger {
                display: block;
            }
            .nav-links {
                position: fixed;
                top: 80px;
                left: -100%;
                width: 100%;
                background: var(--primary);
                flex-direction: column;
                align-items: center;
                padding: 2rem 0;
                transition: 0.3s;
            }
            .nav-links.active {
                left: 0;
            }
            .nav-links li {
                margin: 1rem 0;
            }
            
            .hero h2 {
                font-size: 2.2rem;
            }
            
            .about-content {
                flex-direction: column;
            }
            
            .stats-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Preloader -->
    <div id="preloader">
        <div class="loader"></div>
    </div>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">
            <img src="image/amulogo.png" alt="AMU Logo">
            <h1>AMU SRMS</h1>
        </div>
        <div class="hamburger" id="hamburger">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <ul class="nav-links" id="navLinks">
            <li><a href="#home">HOME</a></li>
            <li><a href="#features">FEATURES</a></li>
            <li><a href="#about">ABOUT</a></li>
            <li><a href="#faq">FAQ</a></li>
            <li><a href="#contact">CONTACT</a></li>
            <li><a href="login.php" class="login-btn">LOGIN</a></li>
        </ul>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-content">
            <h2>Welcome to AMU Service Request Management System</h2>
            <p>Streamlining service requests for Arba Minch University staff and faculty with our digital solution.</p>
            <div class="cta-buttons">
                <a href="login.php" class="btn btn-primary">Get Started</a>
                <a href="#about" class="btn btn-secondary">Learn More</a>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="stats-container">
            <div class="stat-item">
                <i class="fas fa-users"></i>
                <h3 class="counter" data-target="5000">0</h3>
                <p>Active Users</p>
            </div>
            <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <h3 class="counter" data-target="12000">0</h3>
                <p>Requests Completed</p>
            </div>
            <div class="stat-item">
                <i class="fas fa-clock"></i>
                <h3 class="counter" data-target="24">0</h3>
                <p>Avg. Response Time (Hrs)</p>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="section-title">
            <h2>Key Features</h2>
            <p>Discover how our system can transform your service request experience.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <h3>Quick Submission</h3>
                <p>Submit service requests in just a few clicks from anywhere on campus.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>Real-time Tracking</h3>
                <p>Monitor the status of your requests with live updates and notifications.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <h3>Efficient Management</h3>
                <p>Administrators can easily assign and prioritize service requests.</p>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="about">
        <div class="section-title">
            <h2>About The System</h2>
            <p>Learn more about our service request management solution</p>
        </div>
        <div class="about-content">
            <div class="about-text">
                <h3>Digital Transformation for AMU</h3>
                <p>The AMU Service Request Management System (SRMS) is designed to replace the outdated paper-based system with a modern digital solution. Our platform streamlines the entire process from request submission to resolution, saving time and resources for both staff and administrators.</p>
                <p>Developed by the Faculty of Computing and Software Engineering, this system represents AMU's commitment to technological innovation and operational efficiency.</p>
                <a href="#contact" class="btn btn-primary" style="display: inline-block; margin-top: 1rem;">Contact Us</a>
            </div>
            <div class="about-image">
                <img src="https://fetena.net/nw_asset/iNo_18/lm_p/fetena1667209845e251f71a55.jpg" alt="AMU Campus">
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section id="faq" class="faq">
        <div class="section-title">
            <h2>Frequently Asked Questions</h2>
            <p>Find answers to common questions about the SRMS</p>
        </div>
        <div class="accordion">
            <div class="accordion-item">
                <div class="accordion-header">
                    <h3>Who can use this system?</h3>
                    <i class="fas fa-plus"></i>
                </div>
                <div class="accordion-content">
                    <p>The system is available to all AMU staff and faculty members. After logging in with your university credentials, you can submit and track service requests.</p>
                </div>
            </div>
            <div class="accordion-item">
                <div class="accordion-header">
                    <h3>What types of requests can I submit?</h3>
                    <i class="fas fa-plus"></i>
                </div>
                <div class="accordion-content">
                    <p>You can submit requests for various services including electrical, plumbing, carpentry, metal work, and masonry. The system allows you to categorize your request for proper routing.</p>
                </div>
            </div>
            <div class="accordion-item">
                <div class="accordion-header">
                    <h3>How do I track my request status?</h3>
                    <i class="fas fa-plus"></i>
                </div>
                <div class="accordion-content">
                    <p>Once logged in, you can view all your submitted requests on your dashboard. Each request shows its current status (Pending, In Progress, Completed) and assigned technician if applicable.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonials">
        <div class="section-title">
            <h2>What Users Say</h2>
            <p>Feedback from our AMU community</p>
        </div>
        <div class="testimonial-slider">
            <div class="testimonial active">
                <p>"The SRMS saved me hours of paperwork. My plumbing request was resolved in under a day!"</p>
                <div class="author">
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Dr. Alemayehu">
                    <h4>Dr. Alemayehu</h4>
                    <span>Faculty of Engineering</span>
                </div>
            </div>
            <div class="testimonial">
                <p>"Transparent tracking eliminated follow-up calls. Highly recommended!"</p>
                <div class="author">
                    <img src="https://randomuser.me/api/portraits/women/44.jpg" alt="Ms. Tigist">
                    <h4>Ms. Tigist</h4>
                    <span>Administration</span>
                </div>
            </div>
            <div class="testimonial">
                <p>"The mobile-friendly design makes it easy to submit requests from anywhere on campus."</p>
                <div class="author">
                    <img src="https://randomuser.me/api/portraits/men/75.jpg" alt="Mr. Kebede">
                    <h4>Mr. Kebede</h4>
                    <span>Technician</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="contact">
        <div class="section-title">
            <h2>Contact Us</h2>
            <p>Have questions? Reach out to our support team</p>
        </div>
        <div class="contact-container">
            <div class="contact-info">
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <h3>Location</h3>
                        <p>Arba Minch University Main Campus, Administration Building</p>
                    </div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-phone-alt"></i>
                    </div>
                    <div>
                        <h3>Phone</h3>
                        <p>+251 46 881 0496</p>
                    </div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <h3>Email</h3>
                        <p>srms-support@amu.edu.et</p>
                    </div>
                </div>
            </div>
            <div class="contact-form">
                <form id="contactForm">
                    <div class="form-group">
                        <label for="name">Your Name</label>
                        <input type="text" id="name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" required>
                    </div>
                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <input type="text" id="subject" required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" required></textarea>
                    </div>
                    <button type="submit" class="submit-btn">Send Message</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-column">
                <h3>AMU SRMS</h3>
                <p>The Service Request Management System for Arba Minch University, designed to streamline campus service operations.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-linkedin"></i></a>
                </div>
            </div>
            <div class="footer-column">
                <h3>Quick Links</h3>
                <ul class="footer-links">
                    <li><a href="#home">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#about">About</a></li>
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="#contact">Contact</a></li>
                    <li><a href="login.php">Login</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h3>Services</h3>
                <ul class="footer-links">
                    <li><a href="#">Electrical</a></li>
                    <li><a href="#">Plumbing</a></li>
                    <li><a href="#">Carpentry</a></li>
                    <li><a href="#">Metal Work</a></li>
                    <li><a href="#">Masonry</a></li>
                </ul>
            </div>
        </div>
        <div class="copyright">
            <p>&copy; 2023 Arba Minch University - Service Request Management System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Dark Mode Toggle -->
    <button id="darkModeToggle" aria-label="Toggle dark mode">
        <i class="fas fa-moon"></i>
    </button>

    <!-- Back to Top Button -->
    <a href="#home" class="back-to-top" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </a>

    <script>
        // Preloader
        window.addEventListener('load', () => {
            const preloader = document.getElementById('preloader');
            setTimeout(() => {
                preloader.style.opacity = '0';
                setTimeout(() => preloader.remove(), 500);
            }, 1000);
        });

        // Mobile Menu
        const hamburger = document.getElementById('hamburger');
        const navLinks = document.getElementById('navLinks');
        hamburger.addEventListener('click', () => {
            hamburger.classList.toggle('active');
            navLinks.classList.toggle('active');
        });

        // Close mobile menu when clicking a link
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                hamburger.classList.remove('active');
                navLinks.classList.remove('active');
            });
        });

        // Animated Counter
        const counters = document.querySelectorAll('.counter');
        const speed = 200;
        
        function animateCounters() {
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                const count = +counter.innerText;
                const increment = target / speed;
                
                if (count < target) {
                    counter.innerText = Math.ceil(count + increment);
                    setTimeout(animateCounters, 1);
                } else {
                    counter.innerText = target;
                }
            });
        }
        
        // Start counters when stats section is visible
        const statsSection = document.querySelector('.stats');
        const statItems = document.querySelectorAll('.stat-item');
        
        function checkScroll() {
            const statsPosition = statsSection.getBoundingClientRect().top;
            const screenPosition = window.innerHeight / 1.3;
            
            if (statsPosition < screenPosition) {
                statItems.forEach((item, index) => {
                    setTimeout(() => {
                        item.classList.add('visible');
                        if (index === statItems.length - 1) {
                            animateCounters();
                        }
                    }, index * 200);
                });
                window.removeEventListener('scroll', checkScroll);
            }
        }
        
        window.addEventListener('scroll', checkScroll);
        
        // Feature card animations
        const featureCards = document.querySelectorAll('.feature-card');
        
        function animateFeatures() {
            featureCards.forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('visible');
                }, index * 200);
            });
        }
        
        // Start animations when features section is visible
        const featuresSection = document.querySelector('.features');
        
        function checkFeatures() {
            const featuresPosition = featuresSection.getBoundingClientRect().top;
            const screenPosition = window.innerHeight / 1.3;
            
            if (featuresPosition < screenPosition) {
                animateFeatures();
                window.removeEventListener('scroll', checkFeatures);
            }
        }
        
        window.addEventListener('scroll', checkFeatures);
        
        // Testimonials Slider
        let currentTestimonial = 0;
        const testimonials = document.querySelectorAll('.testimonial');
        
        function showTestimonial(index) {
            testimonials.forEach(t => t.classList.remove('active'));
            testimonials[index].classList.add('active');
        }
        
        setInterval(() => {
            currentTestimonial = (currentTestimonial + 1) % testimonials.length;
            showTestimonial(currentTestimonial);
        }, 5000);
        
        // Accordion functionality
        const accordionItems = document.querySelectorAll('.accordion-item');
        
        accordionItems.forEach(item => {
            const header = item.querySelector('.accordion-header');
            
            header.addEventListener('click', () => {
                const currentlyActive = document.querySelector('.accordion-item.active');
                
                if(currentlyActive && currentlyActive !== item) {
                    currentlyActive.classList.remove('active');
                    currentlyActive.querySelector('.accordion-header i').className = 'fas fa-plus';
                }
                
                item.classList.toggle('active');
                
                const icon = item.querySelector('.accordion-header i');
                icon.className = item.classList.contains('active') ? 'fas fa-minus' : 'fas fa-plus';
            });
        });
        
        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href');
                if(targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if(targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // Dark Mode Toggle
        const darkModeToggle = document.getElementById('darkModeToggle');
        
        // Check for saved user preference
        if (localStorage.getItem('darkMode') === 'enabled') {
            document.body.classList.add('dark-mode');
            darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        
        darkModeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const isDarkMode = document.body.classList.contains('dark-mode');
            
            if (isDarkMode) {
                darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                localStorage.setItem('darkMode', 'enabled');
            } else {
                darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                localStorage.setItem('darkMode', 'disabled');
            }
        });
        
        // Back to Top Button
        const backToTop = document.querySelector('.back-to-top');
        window.addEventListener('scroll', () => {
            backToTop.classList.toggle('visible', window.scrollY > 300);
        });
        
        // Form Validation
        const contactForm = document.getElementById('contactForm');
        contactForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const inputs = contactForm.querySelectorAll('input, textarea');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = 'var(--accent)';
                    isValid = false;
                } else {
                    input.style.borderColor = '';
                }
            });
            
            if (isValid) {
                // In a real implementation, you would send the form data to a server here
                alert('Thank you! Your message has been sent.');
                contactForm.reset();
            }
        });
        
        // Initialize animations on page load
        document.addEventListener('DOMContentLoaded', () => {
            // Trigger animations for elements already in view
            checkScroll();
            checkFeatures();
        });
    </script>
</body>
</html>