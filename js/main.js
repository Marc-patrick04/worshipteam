// Reverence Worship Team - Choir Division System JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Add any interactive functionality here

    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-hide messages after 5 seconds
    const messages = document.querySelectorAll('.message');
    messages.forEach(message => {
        setTimeout(() => {
            message.style.opacity = '0';
            setTimeout(() => {
                message.remove();
            }, 300);
        }, 5000);
    });

    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('input[required], select[required], textarea[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = 'red';
                    isValid = false;
                } else {
                    field.style.borderColor = '#ccc';
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });

    // Dynamic form fields for group creation
    const groupCountInput = document.getElementById('group_count');
    if (groupCountInput) {
        groupCountInput.addEventListener('change', function() {
            const count = parseInt(this.value);
            const container = document.getElementById('group_names_container');
            if (container) {
                container.innerHTML = '';
                for (let i = 1; i <= count; i++) {
                    const div = document.createElement('div');
                    div.className = 'form-group';
                    div.innerHTML = `
                        <label for="group_name_${i}">Group ${i} Name:</label>
                        <input type="text" id="group_name_${i}" name="group_names[]" value="Group ${String.fromCharCode(64 + i)}" required>
                    `;
                    container.appendChild(div);
                }
            }
        });
        // Trigger change to initialize
        groupCountInput.dispatchEvent(new Event('change'));
    }
});
