document.addEventListener('DOMContentLoaded', function() {
    console.log('Session ID:', wpAiWorkflowsShortcode.sessionId);
    const shortcodeContainers = document.querySelectorAll('[id^="wp-ai-workflows-output-"]');
    shortcodeContainers.forEach(container => {
        const workflowId = container.dataset.workflowId;

        function fetchOutput() {
            const sessionId = wpAiWorkflowsShortcode.sessionId;

            fetch(`${wpAiWorkflowsShortcode.apiRoot}wp-ai-workflows/v1/shortcode-output?workflow_id=${workflowId}&session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Debug info:', data);
                    if (data.output) {
                        try {
                            const outputData = JSON.parse(data.output);
                            // Find the output node
                            const outputNode = Object.values(outputData).find(node => node.type === 'output');
                            if (outputNode) {
                                // Create a new div element to match content styling
                                const outputDiv = document.createElement('div');
                                outputDiv.style.whiteSpace = 'pre-wrap';
                                outputDiv.style.wordBreak = 'break-word';
                                // Use innerHTML to render HTML content
                                outputDiv.innerHTML = outputNode.content;
                                // Clear previous content and append new div
                                container.innerHTML = '';
                                container.appendChild(outputDiv);
                            } else {
                                container.textContent = 'No output found in the workflow result.';
                            }
                        } catch (e) {
                            console.error('Error parsing output:', e);
                            container.textContent = 'Error parsing output data.';
                        }
                    } else {
                        container.textContent = 'No output available yet.';
                    }
                })
                .catch(error => {
                    console.error('Error fetching output:', error);
                    container.textContent = 'Error fetching output.';
                });
        }

        fetchOutput();
        setInterval(fetchOutput, 5000);
    });
});